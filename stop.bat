@echo off
REM ===========================================================================
REM  L-SIAMS - stop everything start.bat opened
REM
REM  Closes the web, worker and realtime windows. Your database keeps running:
REM  stop MySQL from the XAMPP Control Panel if you want that off too.
REM ===========================================================================

title L-SIAMS - stopping
echo.
echo   Stopping L-SIAMS...
echo.

REM Match on window title rather than image name, so this cannot kill an
REM unrelated PHP process you happen to be running.
for %%W in ("L-SIAMS web" "L-SIAMS worker" "L-SIAMS realtime") do (
    taskkill /fi "WINDOWTITLE eq %%~W*" /t /f >nul 2>&1
    if errorlevel 1 (
        echo   [--] %%~W was not running
    ) else (
        echo   [ok] %%~W stopped
    )
)

echo.
echo   Done. MySQL is still running - stop it from the XAMPP
echo   Control Panel if you no longer need it.
echo.
timeout /t 3 /nobreak >nul
