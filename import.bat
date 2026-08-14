@echo off
REM ===========================================================================
REM  L-SIAMS - import a .sql file into the database
REM
REM  Drag a .sql file onto this icon, or double-click it and type the path
REM  when asked. Works with phpMyAdmin exports.
REM
REM  The file lands in the database named in .env, whatever the file itself
REM  says, and foreign key checks are switched off while it runs - so the
REM  order the tables appear in does not matter.
REM ===========================================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

set "SQLFILE=%~1"

if not defined SQLFILE (
    echo.
    echo   L-SIAMS database import
    echo   -----------------------
    echo.
    echo   Drag the .sql file into this window and press Enter,
    echo   or type its full path.
    echo.
    set /p "SQLFILE=  File: "
)

REM Dragging a file into the window wraps the path in quotes. The quotes must
REM come off before the path is quoted again below, and the stripping itself
REM cannot be written inside quotes - cmd would take the first one as the end
REM of the assignment.
set SQLFILE=!SQLFILE:"=!

if not defined SQLFILE (
    echo   [X] No file given.
    pause
    exit /b 1
)

if not exist "!SQLFILE!" (
    echo   [X] No such file: !SQLFILE!
    pause
    exit /b 1
)

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

echo.
echo   Importing !SQLFILE!
echo.

REM --fresh would drop the existing tables first. It is deliberately not passed
REM here: dragging a file onto an icon should never destroy data by surprise.
REM Run  console.bat db:import "path\to\file.sql" --fresh  to replace instead.
"%PHP%" bin\console db:import "!SQLFILE!"

if errorlevel 1 (
    echo.
    echo   The import stopped. The failing statement is printed above.
    pause
    exit /b 1
)

echo.
echo   Done. If migrations were listed as missing, run:  console.bat migrate
echo.
pause

endlocal
