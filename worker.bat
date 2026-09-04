@echo off
REM ===========================================================================
REM  L-SIAMS - background maintenance
REM
REM  This is the piece that makes the system look after itself. Leave it
REM  running in a window, minimised, on the same machine as the server.
REM
REM  What it does, once a minute:
REM
REM    closes attendance sessions whose period has ended
REM    warns teachers five minutes before a session closes
REM    marks a terminal offline when its heartbeat stops
REM    expires idle web sessions and rotated API keys
REM    takes the daily backup, at the time set in Settings
REM    prunes old backups and old logs to the retention you chose
REM
REM  Without it, "Automatic daily backup" in Settings does nothing at all -
REM  the setting is read here and nowhere else. That is the commonest reason
REM  a school finds it has no backups on the day it needs one.
REM
REM  To have it start by itself every time the computer boots, run
REM  install-worker.bat once. This file is what that scheduled task runs.
REM ===========================================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

set "PHP="
for %%D in (C D E) do (
    if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP (
    where php >nul 2>nul && set "PHP=php"
)

if not defined PHP (
    echo.
    echo   Could not find php.exe.
    echo   XAMPP is not installed, or not on C:, D: or E:.
    echo.
    pause
    exit /b 1
)

echo.
echo   L-SIAMS maintenance worker
echo   Leave this window open. Closing it stops the automatic backup.
echo.

REM A crash should not leave the school without a worker until somebody
REM notices, so it is restarted rather than left dead. The pause keeps a
REM configuration error - a wrong database password, say - from spinning.
:loop
"%PHP%" bin\console worker
echo.
echo   [%date% %time%] worker stopped; restarting in 30 seconds.
echo.
timeout /t 30 /nobreak >nul
goto loop
