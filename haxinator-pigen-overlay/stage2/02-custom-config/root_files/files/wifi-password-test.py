#!/usr/bin/env python3
"""
quickwifi.py – dual-adapter WPA tester with tunable adaptive early-abort
2025-05-30
"""

from __future__ import annotations

import argparse
import queue
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Dict, List, Optional

# ────────────────────────────── helpers ─────────────────────────────────────


def load_wordlist(path: Path) -> List[str]:
    """Load a word-list file into memory (one password per line)."""
    if not path.is_file():
        sys.exit(f"[-] Wordlist '{path}' not found")

    with path.open("r", encoding="utf-8", errors="ignore") as fh:
        return [ln.strip() for ln in fh if ln.strip()]


def safe_scan_bssid(iface, ssid: str, tries: int = 3) -> Optional[str]:
    """
    Attempt to obtain the BSSID for *ssid* on *iface*.
    Returns None if not found after *tries* attempts.
    """
    for _ in range(tries):
        try:
            iface.scan()
        except Exception:
            time.sleep(0.5)
            continue

        time.sleep(1.8)  # allow scan to populate results

        for cell in iface.scan_results():
            if cell.ssid == ssid:
                return cell.bssid
        time.sleep(0.5)

    return None


# ────────────────────────────── worker ──────────────────────────────────────


def pywifi_worker(
    iface,
    ssid: str,
    pwq: "queue.Queue[str]",
    timeout: int,
    adaptive: bool,
    safety: float,
    lock_bssid: bool,
    done: Dict[str, str],
    found: threading.Event,
    debug: bool,
) -> None:
    """
    Worker thread that pulls passwords from *pwq* and tries them via pywifi.
    Sets *found* when a password succeeds and stores it in *done["pw"]*.
    """
    import pywifi
    from pywifi import const

    name = iface.name()
    bssid = safe_scan_bssid(iface, ssid) if lock_bssid else None
    if bssid:
        print(f"[{name}] BSSID {bssid}", flush=True)
        sys.stdout.flush()

    min_ok: Optional[float] = None  # shortest successful connect time
    bounce = 0  # adaptive early-abort back-off counter

    while not found.is_set():
        try:
            pw = pwq.get_nowait()
        except queue.Empty:
            break

        print(f"[{name}] • {pw}", flush=True)
        sys.stdout.flush()

        # Build connection profile
        prof = pywifi.Profile()
        prof.ssid = ssid
        prof.key = pw
        prof.auth = const.AUTH_ALG_OPEN
        prof.akm = [const.AKM_TYPE_WPA2PSK]
        prof.cipher = const.CIPHER_TYPE_CCMP
        prof.bssid = bssid if bssid else None

        nid = iface.add_network_profile(prof)

        iface.disconnect()
        time.sleep(0.1)
        iface.connect(nid)

        start = time.time()
        success = False

        while True:
            status = iface.status()
            elapsed = time.time() - start

            if debug:
                print(f"[{name}]   status={status} {elapsed:.2f}s", flush=True)
                sys.stdout.flush()

            if status == const.IFACE_CONNECTED:
                success = True
                min_ok = elapsed if min_ok is None else min(min_ok, elapsed)
                break

            # Early abort if disconnected quicker than best success − safety
            if (
                adaptive
                and min_ok
                and status == const.IFACE_DISCONNECTED
                and elapsed < min_ok - safety
            ):
                break

            # Give up after *timeout* seconds
            if elapsed > timeout:
                if adaptive and status == const.IFACE_DISCONNECTED:
                    bounce += 1
                    adaptive &= bounce < 3  # disable adaptive after 3 bounces
                break

            time.sleep(0.25)

        # Clean-up
        iface.disconnect()
        time.sleep(0.2)
        iface.remove_network_profile(nid)
        pwq.task_done()

        if success:
            print(f"\n[✓] SUCCESS ({name}) — {pw}\n", flush=True)
            sys.stdout.flush()
            done["pw"] = pw
            found.set()
            break


# ────────────── wpa_cli single-thread fallback (unchanged logic) ────────────


def wpa_cli_run(
    iface: str, ssid: str, words: List[str], timeout: int
) -> Optional[str]:
    """Try *words* on *ssid* using the system's wpa_cli binary."""
    run = lambda *a: subprocess.check_output(
        ["wpa_cli", "-i", iface, *a],
        stderr=subprocess.DEVNULL,
        text=True,
    ).strip()

    nid = run("add_network")
    run("set_network", nid, "ssid", f'"{ssid}"')

    for pw in words:
        print(f"• {pw}", flush=True)
        sys.stdout.flush()
        run("set_network", nid, "psk", f'"{pw}"')
        run("enable_network", nid)

        start = time.time()
        ok = False

        while time.time() - start < timeout:
            status = run("status")

            if "wpa_state=COMPLETED" in status:
                ok = True
                break
            if "wpa_state=DISCONNECTED" in status:
                break
            time.sleep(0.25)

        run("disable_network", nid)
        if ok:
            run("save_config")
            return pw

    run("remove_network", nid)
    return None


# ─────────────────────────────── main ───────────────────────────────────────


def main() -> None:
    import pywifi

    parser = argparse.ArgumentParser(description="Dual-adapter WPA tester")
    parser.add_argument(
        "-i",
        "--iface",
        action="append",
        help="Wi-Fi interface (repeat for multiple)",
    )
    parser.add_argument("-s", "--ssid", required=True)
    parser.add_argument("-w", "--wordlist", required=True, type=Path)
    parser.add_argument(
        "-t", "--timeout", type=int, default=4, help="Connect timeout (s)"
    )
    parser.add_argument("--no-adaptive", action="store_true", help="Disable adaptive mode")
    parser.add_argument(
        "--adaptive-safety",
        type=float,
        default=0.3,
        help="Seconds below best time allowed before early abort",
    )
    parser.add_argument("--skip-bssid", action="store_true", help="Don't lock on BSSID")
    parser.add_argument("--debug", action="store_true", help="Verbose thread debug")
    parser.add_argument(
        "--backend",
        choices=["pywifi", "wpa_cli"],
        default="pywifi",
        help="Backend implementation",
    )

    args = parser.parse_args()

    words = load_wordlist(args.wordlist)
    wifi = pywifi.PyWiFi()

    # Map interface names → objects
    available = {iface.name(): iface for iface in wifi.interfaces()}
    chosen = [available[n] for n in (args.iface or available.keys()) if n in available]

    print(
        f"[+] {len(chosen)} iface(s): "
        f"{', '.join(i.name() for i in chosen)} | "
        f"timeout={args.timeout}s | "
        f"adaptive={'on' if not args.no_adaptive else 'off'}\n",
        flush=True
    )
    sys.stdout.flush()

    # Single-thread wpa_cli path
    if args.backend == "wpa_cli":
        hit = wpa_cli_run(chosen[0].name(), args.ssid, words, args.timeout)
        print(f"[✓] SUCCESS — {hit}" if hit else "[-] No valid password", flush=True)
        sys.stdout.flush()
        return

    # Multi-thread pywifi path
    pw_queue: "queue.Queue[str]" = queue.Queue()
    for w in words:
        pw_queue.put(w)

    done: Dict[str, str] = {}
    found = threading.Event()
    threads: List[threading.Thread] = []

    for iface in chosen:
        t = threading.Thread(
            target=pywifi_worker,
            args=(
                iface,
                args.ssid,
                pw_queue,
                args.timeout,
                not args.no_adaptive,
                args.adaptive_safety,
                not args.skip_bssid,
                done,
                found,
                args.debug,
            ),
            daemon=True,
        )
        t.start()
        threads.append(t)

    for t in threads:
        t.join()

    print(f"[✓] SUCCESS — {done['pw']}" if done.get("pw") else "[-] No valid password", flush=True)
    sys.stdout.flush()


if __name__ == "__main__":
    main()

