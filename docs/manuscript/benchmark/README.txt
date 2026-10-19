L-SIAMS response-time benchmark
================================

What it does
  - Makes a separate copy of L-SIAMS-Lite in %TEMP%\lsiams-benchmark with its
    own empty test database. Your real attendance records are never touched.
  - Sends 3 x 40 taps to the system, each signed exactly like an ESP32 terminal,
    and times every full HTTP round trip (median, 95th percentile, slowest).
  - Runs the system's own throughput test (recording engine alone).
  - Prints the laptop model, processor, RAM, Windows version, PHP and SQLite
    versions (the two missing details in Section 3.2.1).

How to run
  1. The real system can keep running; the benchmark uses port 8099.
  2. Copy this "benchmark" folder to the laptop used as the server.
  3. Double-click run-benchmark.bat.
  4. Press Enter to accept C:\L-SIAMS-Lite-main, or type your folder path.
  5. Wait about one minute, then send benchmark-results.txt to Claude.

Note: the taps go to the copy over the laptop's own loopback (127.0.0.1), so
the result measures the system's processing time on this laptop; the Wi-Fi and
TLS time between a terminal and the server are not included.
