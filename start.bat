@echo off
REM ===========================================================================
REM  L-SIAMS - start everything
REM
REM  Double-click this file. On the first run it sets itself up: copies the
REM  local configuration, generates the cryptographic keys, creates the
REM  database, applies the schema and asks you to make an administrator
REM  account. On every run after that it just starts the system.
REM
REM  Three windows open and stay open while the system runs:
REM     L-SIAMS web       the site itself
REM     L-SIAMS worker    closes expired sessions, marks devices offline
REM     L-SIAMS realtime  live updates on the dashboards
REM
REM  Close them, or run stop.bat, to shut everything down.
REM ===========================================================================

setlocal enabledelayedexpansion
title L-SIAMS launcher
cd /d "%~dp0"

REM The console commands below print check marks and rules as UTF-8; without
REM this the Windows console renders them as mojibake.
chcp 65001 >nul 2>&1

set "WEB_PORT=8080"
set "URL=http://localhost:%WEB_PORT%"

echo.
echo   ================================================
echo    L-SIAMS  -  Attendance Monitoring System
echo   ================================================
echo.

REM ---------------------------------------------------------------- PHP ----
REM XAMPP does not add php.exe to PATH, so look in the usual places first.
set "PHP="
for %%D in (C D E) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"

if not defined PHP (
    echo   [X] Could not find php.exe.
    echo.
    echo       Install XAMPP from https://www.apachefriends.org
    echo       or add PHP to your PATH, then run this again.
    echo.
    pause
    exit /b 1
)

REM Read what PHP says about itself through a temporary file rather than FOR /F.
REM FOR /F hands its command to `cmd /c`, which strips the outermost pair of
REM quotes - that tore the quoted php.exe path away from its -r argument and
REM printed 'php.exe" -r "echo' is not recognized. Running php.exe directly, as
REM every other line here does, has no such rule to trip over.
set "PROBE=%TEMP%\lsiams-probe.txt"

set "PHPVER="
"%PHP%" -r "echo PHP_VERSION;" >"%PROBE%" 2>nul
if exist "%PROBE%" set /p PHPVER=<"%PROBE%"

REM Which php.ini is actually in force. This is the usual surprise when an
REM extension looks enabled but is not: there is more than one on the machine.
set "PHPINI="
"%PHP%" -r "echo php_ini_loaded_file();" >"%PROBE%" 2>nul
if exist "%PROBE%" set /p PHPINI=<"%PROBE%"
del "%PROBE%" >nul 2>&1

if not defined PHPVER set "PHPVER=(version unknown)"
if not defined PHPINI set "PHPINI=C:\xampp\php\php.ini"

echo   [ok] PHP %PHPVER%
echo        %PHP%
echo        %PHPINI%

REM Refuse rather than fail obscurely later. The codebase needs 8.1 features.
"%PHP%" -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);"
if errorlevel 1 (
    echo   [X] PHP 8.1 or newer is required. Please update XAMPP.
    pause
    exit /b 1
)

REM --------------------------------------------------------- extensions ----
REM Split in two on purpose. Without the first four the system cannot start at
REM all. zip and gd only power Excel files, device bundles and profile photos -
REM refusing to run attendance because a photo cannot be resized helps nobody,
REM so those are a warning and the features say so themselves when used.
set "MISSING="
for %%X in (pdo_mysql openssl mbstring json) do (
    "%PHP%" -r "exit(extension_loaded('%%X') ? 0 : 1);"
    if errorlevel 1 set "MISSING=!MISSING! %%X"
)
if defined MISSING (
    echo   [X] PHP is missing:!MISSING!
    echo.
    echo       Open this file in Notepad:
    echo         %PHPINI%
    echo       Find the line for each one - for example  ;extension=mbstring
    echo       Delete the ';' at the start, save the file, and run this again.
    echo.
    pause
    exit /b 1
)
echo   [ok] Required PHP extensions present

set "OPTIONAL="
for %%X in (zip gd) do (
    "%PHP%" -r "exit(extension_loaded('%%X') ? 0 : 1);"
    if errorlevel 1 set "OPTIONAL=!OPTIONAL! %%X"
)
if defined OPTIONAL (
    echo   [--] Optional PHP extensions are off:!OPTIONAL!
    echo.
    echo        zip  Excel .xlsx export and import, device provisioning bundles
    echo        gd   profile photo upload
    echo.
    echo        The system still runs. Attendance, reports as CSV and PDF,
    echo        devices and everything else are unaffected.
    echo.
    echo        To switch them on, open this file in Notepad:
    echo          %PHPINI%
    echo        delete the ';' in front of the matching 'extension=' line,
    echo        save, close this window and run start.bat again.
    echo.
) else (
    echo   [ok] Optional extensions present ^(zip, gd^)
)

REM --------------------------------------------------------------- .env ----
set "FIRSTRUN="
if not exist ".env" (
    set "FIRSTRUN=1"
    echo.
    echo   First run - setting up.
    echo.
    copy /y ".env.local.example" ".env" >nul
    if errorlevel 1 (
        echo   [X] Could not create .env
        pause
        exit /b 1
    )
    echo   [ok] Created .env from .env.local.example

    "%PHP%" bin\console key:generate
    if errorlevel 1 (
        echo   [X] Key generation failed.
        pause
        exit /b 1
    )
)

REM --------------------------------------------------------- database ------
REM Start MySQL yourself in the XAMPP Control Panel; this only checks it.
echo.
echo   Checking the database...
"%PHP%" -r "require 'bootstrap.php'; try { App\Core\Database::instance()->scalar('SELECT 1'); exit(0); } catch (Throwable $e) { exit(2); }" >nul 2>&1

if errorlevel 1 (
    REM Reachable server but missing database is the common first-run case,
    REM so try to create it before giving up.
    echo   [..] Database not reachable - trying to create it
    set "MYSQL="
    for %%D in (C D E) do (
        if not defined MYSQL if exist "%%D:\xampp\mysql\bin\mysql.exe" set "MYSQL=%%D:\xampp\mysql\bin\mysql.exe"
    )
    if defined MYSQL (
        "!MYSQL!" -u root -e "CREATE DATABASE IF NOT EXISTS lsiams_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" >nul 2>&1
    )

    "%PHP%" -r "require 'bootstrap.php'; try { App\Core\Database::instance()->scalar('SELECT 1'); exit(0); } catch (Throwable $e) { exit(2); }" >nul 2>&1
    if errorlevel 1 (
        echo.
        echo   [X] Cannot connect to MySQL.
        echo.
        echo       1. Open the XAMPP Control Panel
        echo       2. Press Start next to MySQL
        echo       3. Run this file again
        echo.
        echo       If MySQL is running but this still fails, check DB_USER
        echo       and DB_PASS in the .env file in this folder.
        echo.
        pause
        exit /b 1
    )
)
echo   [ok] Database connected

REM --------------------------------------------------------- migrations ----
REM `migrate` only applies what is pending, so this is safe on every run.
"%PHP%" bin\console migrate
if errorlevel 1 (
    echo   [X] Could not apply the database schema.
    pause
    exit /b 1
)

if defined FIRSTRUN (
    "%PHP%" bin\console seed
    echo.
    echo   ------------------------------------------------
    echo    Create your administrator account
    echo   ------------------------------------------------
    echo.
    "%PHP%" bin\console user:create-admin
    echo.
    echo   Tip: to fill the system with sample students and
    echo        attendance so every page has data, close this
    echo        and run:   console.bat seed --demo
    echo.
)

REM ------------------------------------------------------------- start -----
REM Refuse early with a readable message. Otherwise php -S fails at the very
REM bottom of the script with "Failed to listen on 0.0.0.0:8080", which looks
REM like the system is broken when in fact it is already running.
netstat -ano | findstr /c:":%WEB_PORT% " | findstr /i "LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo.
    echo   [X] Port %WEB_PORT% is already in use.
    echo.
    echo       Usually this means L-SIAMS is already running - look for a
    echo       window titled "L-SIAMS web", or open %URL%
    echo.
    echo       If it is something else, run stop.bat, or open start.bat in
    echo       Notepad and change WEB_PORT near the top to 8081, then change
    echo       APP_URL in the .env file in this folder to match.
    echo.
    pause
    exit /b 1
)

echo.
echo   Starting...

REM Each runs in its own window so its output stays readable and so closing
REM one does not take the others down.
start "L-SIAMS worker" /min cmd /k ""%PHP%" bin\console worker"
start "L-SIAMS realtime" /min cmd /k ""%PHP%" realtime\server.php"

REM The browser is opened by a helper that waits, because the web server it is
REM meant to reach does not start until the last line of this file. Opening it
REM here would race the server and show "can't reach this page" on a cold start.
start "L-SIAMS browser" /min cmd /c "timeout /t 4 /nobreak >nul & start %URL%"

echo.
echo   ================================================
echo    L-SIAMS is running
echo.
echo      %URL%
echo.
echo    Close this window or press Ctrl+C to stop.
echo    Then run stop.bat to close the other two.
echo   ================================================
echo.

REM Retitle so stop.bat can find this window the same way it finds the others.
title L-SIAMS web

REM -t public makes public\ the web root, so app\, config\ and .env are not
REM reachable over HTTP the way they would be if you pointed a browser at the
REM project folder itself.
"%PHP%" -S 0.0.0.0:%WEB_PORT% -t public public\index.php

REM Reached only when the web server stops. On Ctrl+C that is what you asked
REM for; otherwise it failed, and the reason is in the lines just above - so
REM hold the window open instead of closing it and taking the message with it.
echo.
echo   The web server has stopped. Any error is printed above.
echo.
pause

endlocal
