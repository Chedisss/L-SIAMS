@echo off
REM ===========================================================================
REM  L-SIAMS - get the latest version
REM
REM  Double-click this instead of deleting the folder and downloading the ZIP
REM  again. It fetches only what actually changed - usually a few kilobytes
REM  rather than the whole project - and applies any new database changes.
REM
REM  Your own settings survive every update. The .env file, the student and
REM  teacher photos, the generated reports, the backups and the database
REM  itself are not part of the download and are never touched here.
REM
REM  The first run links this folder to GitHub. After that, updating is
REM  double-click, wait a few seconds, done.
REM ===========================================================================

setlocal
title L-SIAMS update
cd /d "%~dp0"

set "REPO=https://github.com/Chedisss/L-SIAMS.git"
set "BRANCH=main"

echo.
echo   ================================================
echo    L-SIAMS  -  Update
echo   ================================================
echo.

REM ---------------------------------------------------------------- git ----
REM Git for Windows does not always end up on PATH, so check where it
REM installs itself before giving up.
set "GIT="
for %%I in (git.exe) do if not "%%~$PATH:I"=="" set "GIT=%%~$PATH:I"
if not defined GIT if exist "%ProgramFiles%\Git\cmd\git.exe" set "GIT=%ProgramFiles%\Git\cmd\git.exe"
if not defined GIT if exist "%ProgramFiles(x86)%\Git\cmd\git.exe" set "GIT=%ProgramFiles(x86)%\Git\cmd\git.exe"
if not defined GIT if exist "%LocalAppData%\Programs\Git\cmd\git.exe" set "GIT=%LocalAppData%\Programs\Git\cmd\git.exe"

if not defined GIT (
    echo   [X] Git is not installed.
    echo.
    echo       Git is what makes updating fast - it downloads only the
    echo       lines that changed instead of the whole project.
    echo.
    echo       1. Go to  https://git-scm.com/download/win
    echo       2. Run the installer and click Next through every screen.
    echo          The default answers are all correct.
    echo       3. Close this window and double-click update.bat again.
    echo.
    echo       You only ever do this once.
    echo.
    pause
    exit /b 1
)

echo   [ok] Git found
echo        %GIT%

REM ---------------------------------------------------------------- PHP ----
REM Only needed at the end, for the database step. Same search as start.bat.
set "PHP="
for %%D in (C D E) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"

REM ------------------------------------------------------------- linking ---
REM A folder unzipped from GitHub has no .git directory, so it has no idea
REM where it came from. This attaches it to the repository in place, which
REM keeps the .env file and everything else that is not in version control.
REM The prompt below is deliberately not inside an if(...) block. Batch expands
REM %VAR% when it parses a whole block, which is before any line in that block
REM runs - so a %LINK% read back next to the `set /p` that fills it is always
REM the value from before the prompt, i.e. empty. A label sidesteps it.
if exist ".git" goto :update

echo.
echo   This folder came from a ZIP download, so it is not linked
echo   to GitHub yet. Linking it now means every future update is
echo   just a double-click.
echo.
echo   Your .env file, uploaded photos, reports and backups are
echo   not part of the repository and will be left alone. Any edit
echo   you have made to the program files themselves will be
echo   replaced by the official version.
echo.
set "LINK="
set /p "LINK=   Link this folder to GitHub? [Y/N] "
if /i not "%LINK%"=="Y" goto :cancelled

echo.
echo   Linking...
"%GIT%" init -q
if errorlevel 1 goto :gitfailed

"%GIT%" remote add origin "%REPO%"
if errorlevel 1 goto :gitfailed

REM Not a shallow --depth=1 fetch. The entire history of this project is
REM under a megabyte, so truncating it saves nothing worth having, and a
REM shallow repository cannot answer "what changed since my copy" - which
REM is the one question this file exists to answer on every later run.
"%GIT%" fetch -q origin %BRANCH%:refs/remotes/origin/%BRANCH%
if errorlevel 1 goto :gitfailed

"%GIT%" reset -q --hard origin/%BRANCH%
if errorlevel 1 goto :gitfailed

"%GIT%" checkout -q -B %BRANCH%
echo   [ok] Linked to %REPO%
goto :database

:cancelled
echo.
echo   Cancelled. Nothing was changed.
echo.
pause
exit /b 0

REM ------------------------------------------------------------ updating ---
:update
echo.
echo   Checking for updates...

"%GIT%" fetch -q origin %BRANCH%:refs/remotes/origin/%BRANCH%
if errorlevel 1 goto :gitfailed

REM Nothing to do is the common case, and saying so beats a silent no-op.
"%GIT%" diff --quiet HEAD origin/%BRANCH%
if not errorlevel 1 (
    echo   [ok] Already up to date.
    echo.
    echo   Start the system as usual with start.bat
    echo.
    pause
    exit /b 0
)

echo.
echo   What is new:
echo.
"%GIT%" --no-pager log --oneline --no-decorate HEAD..origin/%BRANCH%
echo.
echo   Applying. Any change you made to the program files
echo   themselves will be replaced by the official version.
echo   Your .env, photos, reports and backups are untouched.
echo.

"%GIT%" reset -q --hard origin/%BRANCH%
if errorlevel 1 goto :gitfailed

echo   [ok] Files updated

REM ------------------------------------------------------------ database ---
:database
if not defined PHP (
    echo.
    echo   [!] php.exe not found, so the database step was skipped.
    echo       Run start.bat - it applies pending database changes too.
    echo.
    pause
    exit /b 0
)

if not exist ".env" (
    echo.
    echo   [!] No .env file yet, so there is no database to update.
    echo       Run start.bat - it will set everything up.
    echo.
    pause
    exit /b 0
)

echo.
echo   Applying database changes...
"%PHP%" bin\console migrate
if errorlevel 1 (
    echo.
    echo   [X] The database step failed.
    echo       Is MySQL started in the XAMPP Control Panel?
    echo.
    pause
    exit /b 1
)

echo.
echo   ================================================
echo    Up to date
echo.
echo    Now run start.bat to start the system.
echo    If it was already running, close those windows
echo    first so the new version is loaded.
echo   ================================================
echo.
pause
exit /b 0

:gitfailed
echo.
echo   [X] Git could not reach GitHub.
echo.
echo       Check that this PC is online. If the school network
echo       blocks GitHub, you will have to download the ZIP the
echo       old way - but keep your .env file before you do.
echo.
pause
exit /b 1
