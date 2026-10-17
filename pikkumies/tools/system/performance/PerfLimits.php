<?php
/**
 * PerfLimits: every budget and threshold of the performance unit, in one place (ADR-0035). All of them are
 * provisional: chosen by the design review of 2026-10-17, not measured on a customer server yet; the measured
 * cost of each verb goes into the ADR-0035 addendum. README.md lists every constant in its Budgets table and
 * a test keeps the two in step. The source of each value is on its line.
 */
class PerfLimits
{
    // Runner
    const KILL_AFTER_S = 2;               // provisional: `timeout -k 2`, SIGKILL this long after SIGTERM (a deadline must end the whole run)
    const SCAN_NICE = 19;                 // provisional: lowest CPU priority for walks and probes (ToolProc::gentle precedent)
    const MEASURE_IO_LEVEL = 7;           // provisional: best-effort class, lowest level, for probes (the idle class would measure nothing on a busy disk)

    // check
    const CHECK_WINDOW_S = 2;             // provisional: two /proc samples this far apart (measured file-read cost 0.50 ms per pair on the control node)
    const CHECK_MAX_RECORDS = 10;         // provisional: records per check (Cli LIMIT is 20, BUDGET 16384 bytes)
    const USL_TIMEOUT_S = 10;             // provisional: userShowLimits run time limit (Detect::userShowLimitsData, same as Detect::userShowLimits)
    const MEM_TOP_GROUPS = 5;             // provisional: memory by app, groups shown
    const PSS_TOP = 5;                    // provisional: processes whose smaps_rollup is read (113 ms for 10 measured; the status walk covers the rest)

    // net
    const NET_QUICK_COUNT = 20;           // provisional: quick mtr run, labelled "quick indication, not the wiki procedure"
    const NET_WIKI_COUNT = 1000;          // the wiki procedure's packet count (Pulsed Media wiki, "Network troubleshooting with mtr")
    const NET_COUNT_MAX = 1000;           // provisional: --count upper bound
    const NET_ETA_NOTICE_S = 10;          // provisional: runs longer than this print their ETA first

    // disk (light probe)
    const DISK_WRITES = 16;               // provisional: dd writes with oflag=dsync in scratch
    const DISK_WRITE_BS = 4096;           // provisional: bytes per write
    const DISK_READS = 64;                // provisional: fio random reads
    const DISK_READ_BS = 4096;            // provisional: bytes per read
    const DISK_READ_FILE_MIN = 67108864;  // provisional: an own file of at least 64 MiB to read from
    const DISK_FIND_S = 5;                // provisional: time limit of the search for that file
    const DISK_DEADLINE_S = 20;           // provisional: whole light probe
    const DISK_QUOTA_FACTOR = 2;          // provisional: refuse when free quota < this x probe bytes
    const PROBE_IO_MAX_BYTES = 327680;    // provisional: light probe I/O ceiling, 64 KiB written + 256 KiB read

    // heavy (only on the user's yes, through the guard)
    const HEAVY_DISK_READ_S = 30;         // provisional: fio read test duration
    const HEAVY_DISK_READ_MAX = 1073741824; // provisional: fio read test byte ceiling, 1 GiB
    const HEAVY_DISK_WRITE_BYTES = 268435456; // provisional: write test, 256 MiB, only when the server is not shared
    const HEAVY_DOWNLOAD_MAX = 536870912; // provisional: single-stream download ceiling, 512 MiB
    const HEAVY_DOWNLOAD_S = 60;          // provisional: download time limit
    const HEAVY_IPERF_S = 10;             // provisional: iperf3 seconds each way
    const HEAVY_COOLDOWN_S = 3600;        // provisional: one run per test per hour (a courtesy, not a security limit)
    const HEAVY_TRAFFIC_MARGIN = 0.10;    // provisional: refuse when test bytes > this share of the remaining plan traffic

    // PSI: cpu some, io full, memory full (PMSS userShowLimits reads the same levels)
    const PSI_IO_FULL_AVG10 = 15;         // anchor: io full avg10 burst
    const PSI_IO_FULL_AVG60 = 10;         // anchor: io full avg60 sustained
    const PSI_MEM_FULL_AVG10 = 10;        // provisional, no anchor: heavy gate only
    const PSI_CPU_SOME_AVG10 = 80;        // provisional, no anchor: heavy gate only

    // Router thresholds (PerfRouter)
    const RWND_LIMITED_PCT = 50;          // provisional: own session receive-window limited share of busy time
    const BDP_RTT_MS = 50;                // provisional: single stream at window/rtt with at least this rtt
    const LINK_SPEED_LOW_MBIT = 100;      // provisional: default-route interface speed at or below this
    const MEM_ANON_PCT = 90;              // provisional: anon memory / limit in both samples
    const CPU_THROTTLE_PCT = 20;          // provisional: nr_throttled / nr_periods over the window
    const STEAL_PCT = 5;                  // provisional: CPU steal share
    const NEAR_CAP_PCT = 80;              // provisional: a measured rate at or above this share of its cap counts as at the cap (io cap, CPU quota, window/rtt)
    const TRAFFIC_NEAR_PCT = 90;          // provisional: plan traffic used at or above this share of its limit
    // net and disk probes (PerfNet, PerfDisk)
    const MTR_GRACE_S = 10;      // provisional: mtr waits for late replies after its last cycle, plus start-up
    const TRACEROUTE_S = 40;     // provisional: traceroute -n -q 3 -w 2 -m 30 ends well before this
    const PING_GRACE_S = 5;      // provisional: ping's last reply wait plus start-up
    const NEAR_HOPS = 3;         // provisional (router table): loss starting this close to the server is a ticket
    const DD_MAX_S = 10;          // provisional: share of the 20 s budget the synced writes may use
    const ALLOCATED_SHARE = 0.9;  // provisional: a file to read must have at least this share of its size on disk (not sparse)

    // Sampling (a Hermes no_agent job the agent creates on the user's request)
    const SAMPLE_INTERVAL_MIN = 15;       // provisional: "every 15m"
    const SAMPLE_RUNS = 96;               // provisional: 24 h of samples, then the job ends (repeat 96)
    const SAMPLES_HOURS_DEFAULT = 24;     // provisional: samples --hours default
    const SAMPLES_HOURS_MAX = 720;        // provisional: samples --hours upper bound (30 days)
}
