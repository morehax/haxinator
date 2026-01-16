package nmcli

import (
	"bufio"
	"os"
	"strconv"
	"strings"
	"syscall"
	"time"

	"nm-webui/internal/types"
)

// GetStatus returns overall system and network status
func (c *Client) GetStatus() (*types.Status, error) {
	hostname, _ := os.Hostname()

	// Get nmcli version
	versionOut, _ := c.run("--version")
	version := strings.TrimSpace(versionOut)

	// Get device list with details
	devOut, err := c.runTerse("DEVICE,TYPE,STATE,CONNECTION", "device")
	if err != nil {
		return nil, err
	}

	var devices []types.Device
	var wifiDevices []string

	for _, line := range strings.Split(strings.TrimSpace(devOut), "\n") {
		if line == "" {
			continue
		}
		parts := strings.Split(line, ":")
		if len(parts) < 4 {
			continue
		}

		dev := types.Device{
			Device:     parts[0],
			Type:       parts[1],
			State:      parts[2],
			Connection: parts[3],
		}

		// Get IP info for connected devices
		if dev.State == "connected" {
			ipOut, _ := c.runTerse("IP4.ADDRESS,IP4.GATEWAY,IP4.DNS", "device", "show", dev.Device)
			for _, ipLine := range strings.Split(ipOut, "\n") {
				if strings.HasPrefix(ipLine, "IP4.ADDRESS") {
					dev.IPv4 = strings.TrimPrefix(ipLine, "IP4.ADDRESS[1]:")
				} else if strings.HasPrefix(ipLine, "IP4.GATEWAY") {
					dev.Gateway = strings.TrimPrefix(ipLine, "IP4.GATEWAY:")
				} else if strings.HasPrefix(ipLine, "IP4.DNS") {
					dev.DNS = strings.TrimPrefix(ipLine, "IP4.DNS[1]:")
				}
			}
		}

		devices = append(devices, dev)

		// Track WiFi devices
		if strings.HasSuffix(dev.Type, "wifi") {
			wifiDevices = append(wifiDevices, dev.Device)
		}
	}

	systemInfo := getSystemInfo()

	return &types.Status{
		Hostname:    hostname,
		Time:        time.Now().Format(time.RFC3339),
		NMVersion:   version,
		Devices:     devices,
		WifiDevices: wifiDevices,
		System:      systemInfo,
	}, nil
}

func getSystemInfo() types.SystemInfo {
	uptime := getUptimeSeconds()
	load1, load5, load15 := getLoadAvg()
	memTotal, memAvailable := getMemInfo()
	diskTotal, diskFree := getDiskUsage("/")

	memUsed := uint64(0)
	if memTotal > memAvailable {
		memUsed = memTotal - memAvailable
	}

	diskUsed := uint64(0)
	if diskTotal > diskFree {
		diskUsed = diskTotal - diskFree
	}

	return types.SystemInfo{
		UptimeSeconds: uptime,
		Load1:         load1,
		Load5:         load5,
		Load15:        load15,
		MemTotal:      memTotal,
		MemAvailable:  memAvailable,
		MemUsed:       memUsed,
		DiskTotal:     diskTotal,
		DiskFree:      diskFree,
		DiskUsed:      diskUsed,
	}
}

func getUptimeSeconds() int64 {
	data, err := os.ReadFile("/proc/uptime")
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(data))
	if len(fields) == 0 {
		return 0
	}
	value, err := strconv.ParseFloat(fields[0], 64)
	if err != nil {
		return 0
	}
	return int64(value)
}

func getLoadAvg() (float64, float64, float64) {
	data, err := os.ReadFile("/proc/loadavg")
	if err != nil {
		return 0, 0, 0
	}
	fields := strings.Fields(string(data))
	if len(fields) < 3 {
		return 0, 0, 0
	}
	load1, _ := strconv.ParseFloat(fields[0], 64)
	load5, _ := strconv.ParseFloat(fields[1], 64)
	load15, _ := strconv.ParseFloat(fields[2], 64)
	return load1, load5, load15
}

func getMemInfo() (uint64, uint64) {
	file, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0, 0
	}
	defer file.Close()

	var total uint64
	var available uint64
	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := scanner.Text()
		if strings.HasPrefix(line, "MemTotal:") {
			total = parseMemValue(line)
		} else if strings.HasPrefix(line, "MemAvailable:") {
			available = parseMemValue(line)
		}
		if total > 0 && available > 0 {
			break
		}
	}

	return total, available
}

func parseMemValue(line string) uint64 {
	fields := strings.Fields(line)
	if len(fields) < 2 {
		return 0
	}
	value, err := strconv.ParseUint(fields[1], 10, 64)
	if err != nil {
		return 0
	}
	return value * 1024
}

func getDiskUsage(path string) (uint64, uint64) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return 0, 0
	}
	total := stat.Blocks * uint64(stat.Bsize)
	free := stat.Bavail * uint64(stat.Bsize)
	return total, free
}
