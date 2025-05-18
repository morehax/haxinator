#!/usr/bin/env python3
import socket
import subprocess
import urllib.request
import dns.resolver
import sys
import time
import threading
from concurrent.futures import ThreadPoolExecutor, as_completed

# Configuration
DEBUG = 0  # Set to 1 to debug; set to 0 for silent execution
DNS_RESOLVER = "8.8.8.8"
TEST_DOMAIN = "google.com"
TEST_IP = "8.8.8.8"
HTTP_TEST_URL = "https://google.com"
TCP_TEST_HOST = "143.198.29.141"
TCP_TEST_PORTS = [22]
CUSTOM_SERVER = "143.198.29.141"
TCP_CUSTOM_PORT = 45455
UDP_CUSTOM_PORTS = [1194, 123, 161, 137, 500]
EXPECTED_RESPONSE = "This is the way."
TIMEOUT = 3
HTTP_TIMEOUT = 5
ICMP_PING_COUNT = 2
MAX_WORKERS = 5

# Progress indicator
def progress_indicator(stop_event):
    if DEBUG:
        return
    print("Searching for the way", end="", flush=True)
    while not stop_event.is_set():
        print(".", end="", flush=True)
        time.sleep(0.5)
    print("\r" + " " * 50 + "\r", end="", flush=True)

# Helper Functions
def debug_print(*args, **kwargs):
    if DEBUG:
        print(*args, **kwargs)

def print_result(success, success_msg, fail_msg):
    debug_print(f"[+] {success_msg}" if success else f"[-] {fail_msg}")

def check_server_connectivity(host, port, protocol="TCP"):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM if protocol == "TCP" else socket.SOCK_DGRAM)
        sock.settimeout(TIMEOUT)
        if protocol == "TCP":
            sock.connect((host, port))
            sock.close()
        else:  # UDP
            sock.sendto(b"Test", (host, port))
            sock.recvfrom(1024)
            sock.close()
        debug_print(f"[+] Server connectivity check to {host}:{port} ({protocol}) succeeded")
        return True
    except Exception as e:
        debug_print(f"[-] Server connectivity check to {host}:{port} ({protocol}) failed: {e}")
        return False

def check_dns(resolver):
    try:
        dns_resolver = dns.resolver.Resolver()
        dns_resolver.nameservers = [resolver]
        dns_resolver.timeout = TIMEOUT
        dns_resolver.lifetime = TIMEOUT
        answers = dns_resolver.resolve(TEST_DOMAIN, "A")
        result = [rdata.address for rdata in answers]
        debug_print(f"[+] DNS to {TEST_DOMAIN} via {resolver} succeeded")
        debug_print("\n".join(result))
        return True, result
    except Exception as e:
        debug_print(f"[-] DNS resolution failed or blocked: {e}")
        return False, str(e)

def check_icmp(ip):
    try:
        ping_cmd = ["ping", "-c", str(ICMP_PING_COUNT), ip]
        result = subprocess.run(ping_cmd, capture_output=True, text=True, timeout=TIMEOUT * ICMP_PING_COUNT)
        success = result.returncode == 0
        print_result(success, f"ICMP (ping) to {ip} succeeded.", 
                     f"ICMP (ping) to {ip} failed or blocked: {result.stderr}")
        return success
    except Exception as e:
        print_result(False, "", f"ICMP (ping) to {ip} failed: {e}")
        return False

def check_http():
    try:
        urllib.request.urlopen(HTTP_TEST_URL, timeout=HTTP_TIMEOUT)
        print_result(True, f"HTTP/HTTPS to {HTTP_TEST_URL} succeeded.", "")
        return True
    except Exception as e:
        print_result(False, "", f"HTTP/HTTPS to {HTTP_TEST_URL} failed or blocked: {e}")
        return False

def check_tcp(host, port):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(TIMEOUT)
        sock.connect((host, port))
        sock.close()
        print_result(True, f"TCP to {host}:{port} succeeded.", "")
        return True
    except Exception as e:
        print_result(False, "", f"TCP to {host}:{port} failed or blocked: {e}")
        return False

def check_custom_tcp(host, port):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(TIMEOUT)
        sock.connect((host, port))
        data = sock.recv(1024).decode().strip()
        sock.close()
        success = data == EXPECTED_RESPONSE
        print_result(success, f"Custom TCP to {host}:{port} succeeded. Response: {data}", 
                     f"Custom TCP to {host}:{port} failed or incorrect response. Response: {data}")
        return success
    except Exception as e:
        print_result(False, "", f"Custom TCP to {host}:{port} failed: {e}")
        return False

def check_custom_udp(host, port):
    try:
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.settimeout(TIMEOUT)
        sock.sendto(b"Test", (host, port))
        data, _ = sock.recvfrom(1024)
        sock.close()
        data = data.decode().strip()
        success = data == EXPECTED_RESPONSE
        print_result(success, f"Custom UDP to {host}:{port} succeeded. Response: {data}", 
                     f"Custom UDP to {host}:{port} failed or incorrect response. Response: {data}")
        return success
    except Exception as e:
        print_result(False, "", f"Custom UDP to {host}:{port} failed: {e}")
        return False

def main():
    # Initialize results dictionary dynamically
    results = {
        "DNS Resolution": "FAIL",
        "ICMP (ping)": "FAIL",
        "HTTP/HTTPS": "FAIL",
    }
    for port in TCP_TEST_PORTS:
        results[f"TCP Port ({port})"] = "FAIL"
    results[f"Custom TCP ({TCP_CUSTOM_PORT})"] = "FAIL"
    for port in UDP_CUSTOM_PORTS:
        results[f"Custom UDP ({port})"] = "FAIL"

    # Start progress indicator
    stop_progress = threading.Event()
    progress_thread = threading.Thread(target=progress_indicator, args=(stop_progress,))
    progress_thread.start()

    try:
        # Check server connectivity
        debug_print("----------------------------------------")
        debug_print("[*] Checking server connectivity")
        debug_print("----------------------------------------")
        check_server_connectivity(CUSTOM_SERVER, TCP_CUSTOM_PORT, "TCP")
        for port in UDP_CUSTOM_PORTS:
            check_server_connectivity(CUSTOM_SERVER, port, "UDP")

        # Run tests in parallel
        with ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
            future_to_test = {}

            # IPv4 tests
            future_to_test[executor.submit(check_dns, DNS_RESOLVER)] = "DNS Resolution"
            future_to_test[executor.submit(check_icmp, TEST_IP)] = "ICMP (ping)"
            future_to_test[executor.submit(check_http)] = "HTTP/HTTPS"
            for port in TCP_TEST_PORTS:
                future_to_test[executor.submit(check_tcp, TCP_TEST_HOST, port)] = f"TCP Port ({port})"
            future_to_test[executor.submit(check_custom_tcp, CUSTOM_SERVER, TCP_CUSTOM_PORT)] = f"Custom TCP ({TCP_CUSTOM_PORT})"
            for port in UDP_CUSTOM_PORTS:
                future_to_test[executor.submit(check_custom_udp, CUSTOM_SERVER, port)] = f"Custom UDP ({port})"

            # Collect results
            for future in as_completed(future_to_test):
                test_name = future_to_test[future]
                try:
                    result = future.result()
                    success = result[0] if isinstance(result, tuple) else result
                    if success:
                        results[test_name] = "PASS"
                except Exception as e:
                    debug_print(f"Test {test_name} failed: {e}")

    finally:
        stop_progress.set()
        progress_thread.join()

    # Print summary
    print("\n========== Outbound Connectivity Test Summary ==========")
    print(f"{'Test':<30} {'Result':<6}")
    print(f"{'-'*30} {'-'*6}")
    for test, result in sorted(results.items()):
        print(f"{test:<30} {result}")
    print("=========================================================")

if __name__ == "__main__":
    main()
