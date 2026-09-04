@echo off
REM ===========================================================================
REM  L-SIAMS - stop the maintenance worker starting automatically
REM
REM  Removes the scheduled task that install-worker.bat created. The system
REM  keeps working, but nothing takes the daily backup any more and attendance
REM  sessions are only closed while worker.bat is running in a window.
REM
REM  Right-click and choose "Run as administrator".
REM ===========================================================================

net session >nul 2>nul
if errorlevel 1 (
    echo.
    echo   This needs administrator rights. Right-click and choose
    echo   "Run as administrator".
    echo.
    pause
    exit /b 1
)

schtasks /end    /tn "L-SIAMS Worker" >nul 2>nul
schtasks /delete /tn "L-SIAMS Worker" /f

echo.
echo   Removed. The automatic daily backup will not run until you register
echo   it again with install-worker.bat, or start worker.bat by hand.
echo.
pause
