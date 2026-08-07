@echo off
REM ===========================================================================
REM  L-SIAMS — start everything
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

REM `php -v` rather than `php -r "echo PHP_VERSION;"`. A command inside FOR /F
REM is parsed twice - once here, then again by the `cmd /c` that FOR spawns -
REM and the -r form does not always survive the second pass: the terminating
REM semicolon is dropped, PHP is handed a statement with no terminator, and it
REM prints "Parse error: ... expecting "," or ";"" to stdout. That parse error
REM is then what this loop captures, so the launcher reported the version as
REM "error:" and carried on. `php -v` carries no quotes, no semicolon and no
REM operators of its own, so a second round of parsing has nothing to damage.
REM
REM Left as a bare `"path\php.exe" -v`, with no pipe and no redirection, so
REM `cmd /c` takes its documented path of keeping the quotes around a command
REM that is nothing but a quoted executable and its switches. Piping this into
REM findstr to filter it would put a special character back into the string and
REM hand the quote handling straight back to the case that failed above.
REM
REM So the filtering happens in batch instead. `php -v` prints five lines and
REM only the first begins with "PHP"; the rest are the copyright notice, whose
REM second word would otherwise land in PHPVER. The digit test rejects a PHP
REM startup warning, which begins with "PHP" as well but reads "PHP Warning:".
set "PHPVER="
for /f "tokens=1,2 delims= " %%A in ('"%PHP%" -v') do (
    if not defined PHPVER if /i "%%A"=="PHP" (
        set "VERTOKEN=%%B"
        if "!VERTOKEN:~0,1!" geq "0" if "!VERTOKEN:~0,1!" leq "9" set "PHPVER=%%B"
    )
)
if not defined PHPVER set "PHPVER=(version not detected)"

echo   [ok] PHP %PHPVER%
echo        %PHP%

REM Refuse rather than fail obscurely later. The codebase needs 8.1 features.
"%PHP%" -r "exit(PHP_VERSION_ID >= 80100 ? 0 : 1);"
if errorlevel 1 (
    echo   [X] PHP 8.1 or newer is required. Please update XAMPP.
    pause
    exit /b 1
)

REM --------------------------------------------------------- extensions ----
REM Nothing runs without these.
set "MISSING="
for %%X in (pdo_mysql openssl mbstring json) do (
    "%PHP%" -r "exit(extension_loaded('%%X') ? 0 : 1);"
    if errorlevel 1 set "MISSING=!MISSING! %%X"
)
if defined MISSING (
    echo   [X] PHP is missing:!MISSING!
    echo.
    echo       Open C:\xampp\php\php.ini, remove the ';' in front of the
    echo       matching 'extension=' lines, save, and run this again.
    echo.
    pause
    exit /b 1
)

REM These only break individual features, so warn and carry on rather than
REM stopping someone from using the rest of the system.
set "OPTMISSING="
for %%X in (zip gd) do (
    "%PHP%" -r "exit(extension_loaded('%%X') ? 0 : 1);"
    if errorlevel 1 set "OPTMISSING=!OPTMISSING! %%X"
)
if defined OPTMISSING (
    echo   [warn] PHP is missing:!OPTMISSING!
    echo.
    echo       Everything still runs, but these stay broken until you add them:
    echo         zip  -  Excel exports, firmware downloads
    echo         gd   -  profile photo uploads
    echo.
    echo       To fix: open C:\xampp\php\php.ini, delete the ';' at the start
    echo       of the 'extension=zip' and 'extension=gd' lines, save, then run
    echo       this file again.
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
