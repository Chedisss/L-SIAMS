@echo off
REM ===========================================================================
REM  L-SIAMS - start the maintenance worker automatically
REM
REM  Run this once. It registers a Windows scheduled task that starts
REM  worker.bat whenever the computer boots, so the daily backup and the
REM  session housekeeping keep running without anybody remembering to start
REM  them.
REM
REM  Windows has no cron. The Settings page used to tell you to "run the
REM  worker with cron", which is advice from a different operating system and
REM  is why the automatic backup never ran on this one.
REM
REM  Right-click this file and choose "Run as administrator" - registering a
REM  task that runs at boot needs it.
REM
REM  To undo: run uninstall-worker.bat, or open Task Scheduler and delete the
REM  task named L-SIAMS Worker.
REM ===========================================================================

setlocal
cd /d "%~dp0"

net session >nul 2>nul
if errorlevel 1 (
    echo.
    echo   This needs administrator rights.
    echo.
    echo   Close this window, right-click install-worker.bat and choose
    echo   "Run as administrator", then try again.
    echo.
    pause
    exit /b 1
)

set "TASK=L-SIAMS Worker"

schtasks /query /tn "%TASK%" >nul 2>nul
if not errorlevel 1 (
    echo.
    echo   The task already exists. Replacing it with the current path.
    echo.
    schtasks /delete /tn "%TASK%" /f >nul 2>nul
)

REM /RU SYSTEM so it runs without anybody signed in - a school server sits at
REM the login screen most of the time, and a task tied to a user account would
REM only run once somebody logged in.
schtasks /create ^
    /tn "%TASK%" ^
    /tr "\"%~dp0worker.bat\"" ^
    /sc onstart ^
    /ru SYSTEM ^
    /rl HIGHEST ^
    /f

if errorlevel 1 (
    echo.
    echo   Could not register the task. The worker still runs if you
    echo   double-click worker.bat and leave the window open.
    echo.
    pause
    exit /b 1
)

echo.
echo   Registered. The worker will start automatically at every boot.
echo.
echo   Starting it now so you do not have to restart the computer:
schtasks /run /tn "%TASK%" >nul 2>nul

echo.
echo   Check it is working: open Settings, confirm "Automatic daily backup"
echo   is ticked and note the time. Tomorrow, the Backups page should show a
echo   new archive with trigger "scheduled".
echo.
pause
