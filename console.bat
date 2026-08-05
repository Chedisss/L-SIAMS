@echo off
REM ===========================================================================
REM  L-SIAMS - run a console command without hunting for php.exe
REM
REM    console.bat check                why will it not start? PHP, .env, database
REM    console.bat seed --demo          fill the system with sample data
REM    console.bat user:create-admin    add another administrator
REM    console.bat backup               take an encrypted backup now
REM    console.bat migrate:status       show which migrations have run
REM    console.bat schema:dump          rebuild the phpMyAdmin import file
REM    console.bat                      list every command
REM ===========================================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

REM The console prints check marks and rules as UTF-8. Without this the Windows
REM console renders them as mojibake, which makes a diagnostic screen look like
REM a fault of its own.
chcp 65001 >nul 2>&1

set "PHP="
for %%D in (C D E) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"

if not defined PHP (
    echo   [X] Could not find php.exe. Install XAMPP, or add PHP to your PATH.
    pause
    exit /b 1
)

REM %* passes every argument through untouched, so quoted arguments survive.
"%PHP%" bin\console %*

REM Only pause when double-clicked, so the output is readable. Run from a
REM terminal and it returns immediately, which is what a script would want.
echo %CMDCMDLINE% | find /i "/c" >nul && pause

endlocal
