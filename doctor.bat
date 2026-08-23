@echo off
REM ===========================================================================
REM  L-SIAMS - what is wrong with this installation?
REM
REM  Double-click this when something is not working and it is not obvious
REM  why: a terminal that will not connect, fingerprints that stop matching,
REM  a page that reports nothing, or a system that worked yesterday.
REM
REM  It checks, in order: PHP and its extensions, the .env keys, the database
REM  connection, whether every migration has been applied, whether APP_KEY
REM  still opens the encrypted data, what the system contains, the state of
REM  every registered terminal, and whether live updates are running.
REM
REM  Nothing here changes anything. It only looks, so it is safe to run
REM  against a school installation while classes are in progress.
REM ===========================================================================

setlocal enabledelayedexpansion
title L-SIAMS - doctor
cd /d "%~dp0"

echo.
echo   ================================================
echo    L-SIAMS  -  Doctor
echo   ================================================
echo.

REM ---------------------------------------------------------------- PHP ----
REM Same search as start.bat and update.bat: XAMPP does not put php.exe on
REM PATH, so look where it installs itself before giving up on it.
set "PHP="
for %%D in (C D E) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"

if not defined PHP (
    echo   [X] Could not find php.exe.
    echo.
    echo       This normally means XAMPP is not installed, or is installed
    echo       somewhere other than C:\xampp, D:\xampp or E:\xampp.
    echo.
    pause
    exit /b 1
)

if not exist ".env" (
    echo   [!] There is no .env file, so this folder has not been set up yet.
    echo.
    echo       Run start.bat first - it creates the .env, the database and
    echo       the first administrator account.
    echo.
    echo       Moved here from another PC? Copy the ORIGINAL .env across
    echo       rather than making a new one. See docs\MOVING-TO-ANOTHER-PC.md
    echo       - the key inside it is what decrypts the fingerprints and the
    echo       terminals' secrets, and it cannot be regenerated.
    echo.
    pause
    exit /b 1
)

"%PHP%" bin\console doctor

echo.
echo   ================================================
echo    Finished. Nothing above was changed.
echo.
echo    A cross next to "All migrations applied" is fixed
echo    by running update.bat.
echo.
echo    A cross under "Encryption key" means the .env and
echo    the database are from different installations -
echo    see docs\MOVING-TO-ANOTHER-PC.md
echo   ================================================
echo.
pause
endlocal
