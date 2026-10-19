@echo off
REM ===========================================================================
REM  L-SIAMS response-time benchmark
REM
REM  Runs on a COPY of L-SIAMS-Lite with its own empty test database, so the
REM  real attendance records are never read or changed. It sends 3 x 40 taps
REM  signed exactly like the ESP32 terminals, times each one, runs the
REM  system's own throughput test, and prints the laptop's specifications.
REM
REM  Usage: put this folder anywhere, double-click run-benchmark.bat, and
REM  give the L-SIAMS-Lite folder when asked (default C:\L-SIAMS-Lite-main).
REM  The results are also saved to benchmark-results.txt in this folder.
REM ===========================================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"
title L-SIAMS benchmark

set "PHP="
for %%D in (C D E) do if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"
if not defined PHP (echo [X] PHP was not found. Install XAMPP or add php.exe to PATH. & pause & exit /b 1)

set "SRC=C:\L-SIAMS-Lite-main"
set /p "SRC=L-SIAMS-Lite folder [%SRC%]: "
if not exist "%SRC%\bootstrap.php" (echo [X] "%SRC%" is not an L-SIAMS-Lite folder. & pause & exit /b 1)

set "OUT=%~dp0benchmark-results.txt"
call :main > "%OUT%" 2>&1
type "%OUT%"
echo.
echo Results saved to %OUT%
pause
exit /b 0

:main
set "DST=%TEMP%\lsiams-benchmark"
echo L-SIAMS response-time benchmark   %DATE% %TIME%
echo ==================================================================
echo.
echo Laptop
powershell -NoProfile -Command "$c=Get-CimInstance Win32_ComputerSystem; $p=Get-CimInstance Win32_Processor | Select-Object -First 1; $o=Get-CimInstance Win32_OperatingSystem; '  Model     : ' + $c.Manufacturer + ' ' + $c.Model; '  Processor : ' + $p.Name.Trim(); '  RAM       : ' + [math]::Round($c.TotalPhysicalMemory/1GB,1) + ' GB'; '  Windows   : ' + $o.Caption + ' (version ' + $o.Version + ', build ' + $o.BuildNumber + ')'"
"%PHP%" -r "echo '  PHP       : ', PHP_VERSION, PHP_EOL, '  SQLite    : ', (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn(), PHP_EOL;"
echo.

echo Preparing a separate copy in %DST% (the real system is not touched)...
if exist "%DST%" rmdir /s /q "%DST%"
robocopy "%SRC%" "%DST%" /E /XD "%SRC%\storage\database" "%SRC%\storage\backups" "%SRC%\storage\logs" "%SRC%\.git" /NFL /NDL /NJH /NJS /NP >nul
if not exist "%DST%\storage\database" mkdir "%DST%\storage\database"
if not exist "%DST%\storage\logs" mkdir "%DST%\storage\logs"
copy /y "%DST%\.env.example" "%DST%\.env" >nul
pushd "%DST%"
"%PHP%" bin\console key:generate >nul 2>&1
powershell -NoProfile -Command "$f='.env'; (Get-Content $f) -replace '^DB_PATH=.*','DB_PATH=storage/database/benchmark.sqlite' -replace '^TRUSTED_WEB_CIDRS=.*','TRUSTED_WEB_CIDRS=127.0.0.0/8' -replace '^TRUSTED_DEVICE_CIDRS=.*','TRUSTED_DEVICE_CIDRS=127.0.0.0/8' -replace '^TRUST_PROXY=.*','TRUST_PROXY=true' | Set-Content $f"
"%PHP%" bin\console migrate >nul 2>&1
"%PHP%" bin\console seed >nul 2>&1
start "LSIAMS-BENCH-SERVER" /min "%PHP%" -S 127.0.0.1:8099 -t public bin\router.php
timeout /t 3 /nobreak >nul

echo.
echo 1. Full HTTP taps through the web application (3 runs x 40 signed taps)
for /l %%R in (1,1,3) do (
  "%PHP%" "%~dp0bench_setup.php" "%DST%" > "%DST%\bench-creds.json"
  "%PHP%" "%~dp0bench_client.php" "%DST%\bench-creds.json" http://127.0.0.1:8099 40
)
echo.
echo 2. Recording engine alone (system's own test: 50 taps, no web server)
"%PHP%" tests\concurrency\run.php --only=throughput
popd
taskkill /fi "WINDOWTITLE eq LSIAMS-BENCH-SERVER*" /t /f >nul 2>&1
echo.
echo Done. The copy in %DST% can be deleted.
exit /b 0
