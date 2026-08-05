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

REM The extra outer quotes are not a typo. FOR /F hands this to `cmd /c`,
REM which strips the first and last quote it sees. Without the spare pair it
REM would eat the ones around the php.exe path and the -r script.
for /f "tokens=2 delims= " %%V in ('""%PHP%" -r "echo 'PHP ' . PHP_VERSION;""') do set "PHPVER=%%V"
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

endlocal
