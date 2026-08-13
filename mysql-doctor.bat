@echo off
REM ===========================================================================
REM  L-SIAMS - why will MySQL not start?
REM
REM  Double-click this when the XAMPP Control Panel shows MySQL going green and
REM  then immediately red, or when start.bat cannot reach the database.
REM
REM  It answers the three questions that identify almost every case, and then
REM  runs MySQL in this window so the error it is too abrupt to write to its
REM  log file has somewhere to go.
REM
REM  Nothing here changes anything. It only looks.
REM ===========================================================================

setlocal enabledelayedexpansion
title L-SIAMS - MySQL doctor
cd /d "%~dp0"

echo.
echo   ================================================
echo    L-SIAMS  -  MySQL doctor
echo   ================================================
echo.

REM ------------------------------------------------------------- locate ----
REM Typing this path by hand is where people lose an evening: "C:xampp" without
REM the backslash is a valid relative path that does not exist, and the error
REM says only "the system cannot find the path specified".
set "MYSQLD="
for %%D in (C D E) do (
    if not defined MYSQLD if exist "%%D:\xampp\mysql\bin\mysqld.exe" set "MYSQLD=%%D:\xampp\mysql\bin\mysqld.exe"
)

if not defined MYSQLD (
    echo   [X] Could not find mysqld.exe in C:\xampp, D:\xampp or E:\xampp.
    echo.
    echo       If XAMPP is somewhere else, open this file in Notepad and add
    echo       that drive letter to the line beginning "for %%%%D in".
    echo.
    pause
    exit /b 1
)

for %%P in ("%MYSQLD%") do set "MYSQLBIN=%%~dpP"
echo   [ok] Found MySQL
echo        %MYSQLD%
echo.

REM --------------------------------------------------------------- port ----
REM A second MySQL holding 3306 is the most common cause of "starts, then
REM stops": the new process binds, finds the port taken, and exits before it
REM manages to write the reason anywhere.
echo   Checking whether anything already holds port 3306...
set "PORTBUSY="
for /f "tokens=5" %%A in ('netstat -ano ^| findstr /r /c:":3306 .*LISTENING"') do set "PORTBUSY=%%A"

if defined PORTBUSY (
    echo   [!] Port 3306 is already in use by process ID !PORTBUSY!
    echo.
    for /f "tokens=1" %%N in ('tasklist /fi "pid eq !PORTBUSY!" /nh 2^>nul') do echo        That process is: %%N
    echo.
    echo       If MySQL is stopped in the XAMPP Control Panel, this is another
    echo       MySQL - usually one installed separately, running as a Windows
    echo       service. Stop it:
    echo.
    echo         1. Press Win+R, type  services.msc  and press Enter
    echo         2. Find MySQL / MySQL80 / MariaDB - anything not XAMPP's
    echo         3. Right-click, Stop. Then Properties, Startup type: Manual
    echo         4. Start MySQL in the XAMPP Control Panel
    echo.
) else (
    echo   [ok] Port 3306 is free
    echo.
)

REM ---------------------------------------------------------- processes ----
echo   Checking for MySQL processes already running...
tasklist /fi "imagename eq mysqld.exe" /nh 2>nul | findstr /i mysqld >nul
if errorlevel 1 (
    echo   [ok] No mysqld.exe running
) else (
    echo   [!] mysqld.exe is already running:
    tasklist /fi "imagename eq mysqld.exe" /nh 2>nul
)
echo.

REM -------------------------------------------------------------- start ----
echo   ================================================
echo    Starting MySQL in this window
echo   ================================================
echo.
echo   Errors at this stage often never reach mysql_error.log, because the
echo   process dies before it can write them. Running it here gives them
echo   somewhere to go.
echo.
echo   What you are looking for:
echo.
echo     - It prints lines and then STOPS, returning to a prompt.
echo       The last few lines are the reason. Copy them.
echo.
echo     - It prints "ready for connections" and SITS THERE.
echo       That means MySQL works. Leave this window open, run start.bat,
echo       and close this window when you are finished.
echo.
echo   Press Ctrl+C to stop it at any time.
echo.
pause
echo.

cd /d "%MYSQLBIN%"
mysqld.exe --defaults-file=my.ini --console

echo.
echo   ================================================
echo    MySQL exited
echo   ================================================
echo.
echo   The reason is in the last few lines above. If they mention:
echo.
echo     "Bind on TCP/IP port"     another MySQL owns 3306 - see above
echo     "aria_log"               delete files starting with aria_log from
echo                              C:\xampp\mysql\data, then try again
echo     "Table 'mysql.*'"        the system tables are from another version
echo.
echo   Copy the last 15 lines if none of those match.
echo.
echo   Your data lives in C:\xampp\mysql\data\lsiams_db - copy that folder
echo   somewhere safe before trying any fix that touches the data directory,
echo   and never delete ibdata1.
echo.
pause
