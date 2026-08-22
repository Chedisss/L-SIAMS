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

REM Every question below is asked by bin\env-check.php rather than by an
REM inline -r snippet. PHP source passed through a FOR /F goes through two
REM rounds of cmd.exe quote-stripping, and a machine that strips it differently
REM produced a parse error from the probe and then printed the second word of
REM that error as the PHP version. A file has no quoting to get wrong.
for /f "delims=" %%V in ('"%PHP%" bin\env-check.php version 2^>nul') do set "PHPVER=%%V"

if not defined PHPVER (
    echo   [X] PHP was found but would not run.
    echo.
    echo       %PHP%
    echo.
    echo       Run this by hand to see why:
    echo         "%PHP%" bin\env-check.php version
    echo.
    pause
    exit /b 1
)

echo   [ok] PHP %PHPVER%
echo        %PHP%

REM Refuse rather than fail obscurely later. The codebase needs 8.1 features.
"%PHP%" bin\env-check.php version >nul 2>&1
if errorlevel 1 (
    echo   [X] PHP 8.1 or newer is required ^(found %PHPVER%^). Please update XAMPP.
    pause
    exit /b 1
)

REM --------------------------------------------------------- extensions ----
REM Nothing runs without these.
set "MISSING="
for /f "delims=" %%X in ('"%PHP%" bin\env-check.php required 2^>nul') do set "MISSING=%%X"
REM The remedy comes from PHP itself, which knows the php.ini it loaded. The
REM launcher used to print "Open C:\xampp\php\php.ini" whichever PHP it had
REM found, so a standalone build picked up from PATH sent people to edit an ini
REM belonging to a different interpreter - or to one that does not exist, since
REM a PHP unpacked from the .zip ships no php.ini at all.
REM
REM Printed straight through rather than captured: FOR /F drops blank lines,
REM which would run the whole remedy together into a single block.
if defined MISSING (
    echo   [X] PHP is missing: !MISSING!
    echo.
    "%PHP%" bin\env-check.php inihelp required
    echo.
    pause
    exit /b 1
)

REM These only break individual features, so warn and carry on rather than
REM stopping someone from using the rest of the system.
set "OPTMISSING="
for /f "delims=" %%X in ('"%PHP%" bin\env-check.php optional 2^>nul') do set "OPTMISSING=%%X"
if defined OPTMISSING (
    echo   [warn] PHP is missing: !OPTMISSING!
    echo.
    echo       Everything still runs, but these stay broken until you add them:
    echo         zip  -  Excel exports, firmware downloads
    echo         gd   -  profile photo uploads
    echo.
    echo       To fix:
    echo.
    "%PHP%" bin\env-check.php inihelp optional
    echo.
) else (
    echo   [ok] Required PHP extensions present
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
"%PHP%" bin\env-check.php database >nul 2>&1

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

    "%PHP%" bin\env-check.php database >nul 2>&1
    if errorlevel 1 (
        echo.
        echo   [X] Cannot connect to MySQL.
        echo.
        REM Re-run without the redirect. The check knows exactly which of the
        REM three failures this is - nothing listening, something listening
        REM that is not MySQL, or MySQL refusing the credentials - and the
        REM launcher guessing "start MySQL" at all three sent people to the
        REM Control Panel to look at a service that was already running.
        "%PHP%" bin\env-check.php database
        echo.
        echo       Most often this is simply MySQL not started:
        echo         1. Open the XAMPP Control Panel
        echo         2. Press Start next to MySQL
        echo         3. Run this file again
        echo.
        echo       If MySQL is running and the message above says otherwise,
        echo       check DB_HOST, DB_PORT, DB_USER and DB_PASS in the .env
        echo       file in this folder.
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
echo.
echo   Starting...

REM Each runs in its own window so its output stays readable and so closing
REM one does not take the others down.
start "L-SIAMS worker" /min cmd /k ""%PHP%" bin\console worker"
start "L-SIAMS realtime" /min cmd /k ""%PHP%" realtime\server.php"

REM The web server runs in this window; closing it stops the site.
timeout /t 2 /nobreak >nul
start "" "%URL%"

REM Find this PC's address on the school network so the other devices can be
REM told where to go. Picking the first IPv4 that is not loopback is right on
REM the overwhelmingly common single-adapter machine; a PC on both Wi-Fi and
REM Ethernet may show the other one, which is why the address is printed for
REM the operator to read rather than written into any configuration.
set "LANIP="
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /c:"IPv4 Address"') do (
    if not defined LANIP (
        for /f "tokens=* delims= " %%B in ("%%A") do (
            if not "%%B"=="127.0.0.1" set "LANIP=%%B"
        )
    )
)

echo.
echo   ================================================
echo    L-SIAMS is running
echo.
echo      On this PC:        %URL%
if defined LANIP (
    echo      On other devices:  http://%LANIP%:%WEB_PORT%
    echo.
    echo    Phones, tablets and laptops on the same Wi-Fi can
    echo    open that second address. The first time, Windows
    echo    Firewall will ask to allow PHP - choose Private
    echo    networks and click Allow access. If nothing loads,
    echo    see docs\NETWORK-ACCESS.md
) else (
    echo.
    echo    This PC has no network address yet, so other devices
    echo    cannot reach it. Connect it to the school Wi-Fi or
    echo    plug in the network cable, then run this file again.
)
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
REM
REM bin\router.php is the router script, not the front controller. The built-in
REM server sends every request to its router, so pointing this at index.php
REM directly would route CSS and images like pages and serve the site unstyled.
REM The router hands real files back and passes everything else to index.php.
"%PHP%" -S 0.0.0.0:%WEB_PORT% -t public bin\router.php

endlocal
