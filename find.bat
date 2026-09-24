@echo off
REM ===========================================================================
REM  L-SIAMS - find.bat   "what did they change, and how do I fix it?"
REM
REM  doctor.bat tells you the system is broken. find.bat tells you WHERE and
REM  gives you the working code back.
REM
REM  How it works:
REM    1) Run  find.bat save   ONCE, while everything still works.
REM       It remembers a private copy of your code in  .lsiams_baseline\
REM    2) After the panel changes / deletes something, run  find.bat
REM       It scans every PHP file for errors, compares the whole codebase to
REM       the remembered copy, and tells you exactly what is different.
REM    3) It shows you how to fix each thing. To restore automatically:
REM       find.bat fix            restore everything to the working copy
REM       find.bat fix <file>     restore just one file
REM
REM  Commands:
REM       find.bat save     remember the current (working) code
REM       find.bat          scan and report problems  (default)
REM       find.bat fix      restore changed / deleted files from the memory
REM       find.bat help     show help and how-to-fix notes
REM
REM  Nothing but 'fix' ever changes your project. 'save' and the scan only look.
REM ===========================================================================

setlocal enabledelayedexpansion
title L-SIAMS - find
cd /d "%~dp0"

set "BASE=%CD%\.lsiams_baseline"
set "ROOTS=app bin config routes realtime database public"
set "ROOTFILES=bootstrap.php composer.json .env"

REM -------------------------------------------------------------- find PHP ----
REM Same search doctor.bat / start.bat use: XAMPP does not put php.exe on PATH.
set "PHP="
for %%D in (C D E) do (
    if not defined PHP if exist "%%D:\xampp\php\php.exe" set "PHP=%%D:\xampp\php\php.exe"
)
if not defined PHP for %%I in (php.exe) do if not "%%~$PATH:I"=="" set "PHP=%%~$PATH:I"
if not defined PHP (
    echo   [X] Could not find php.exe. Install XAMPP, or run from a shell where
    echo       php is on PATH. Syntax checking and the database test need it.
    echo.
)

REM ------------------------------------------------------------- dispatch ----
if /I "%~1"=="save"  goto :SAVE
if /I "%~1"=="help"  goto :HELP
if /I "%~1"=="/?"    goto :HELP
if /I "%~1"=="fix"   goto :FIX
goto :SCAN


REM =====================================================================  SAVE
:SAVE
echo.
echo   ================================================
echo    L-SIAMS  -  remembering the working code
echo   ================================================
echo.
if exist "%BASE%" (
    echo   A memory already exists from a previous save.
    set /p "OW=  Overwrite it with the code as it is RIGHT NOW? (Y/N) "
    if /I not "!OW!"=="Y" (
        echo   Cancelled. Nothing changed.
        echo.
        pause
        goto :EOF
    )
    rmdir /s /q "%BASE%" 2>nul
)
mkdir "%BASE%" 2>nul

for %%R in (%ROOTS%) do (
    if exist "%CD%\%%R" (
        echo   Remembering %%R\ ...
        robocopy "%CD%\%%R" "%BASE%\%%R" *.php /S /XD "%CD%\public\uploads" /NFL /NDL /NJH /NJS /NP /NS /NC >nul
    )
)
for %%F in (%ROOTFILES%) do (
    if exist "%CD%\%%F" copy /y "%CD%\%%F" "%BASE%\%%F" >nul
)
echo saved %DATE% %TIME%> "%BASE%\_saved_on.txt"

echo.
echo   [OK] Working code remembered in  .lsiams_baseline\
echo.
echo        Keep that folder. After the panel changes something, run:
echo            find.bat
echo.
echo   NOTE: the memory contains a copy of your .env (database settings).
echo         Keep this machine private, and you may delete .lsiams_baseline\
echo         after the test with:   rmdir /s /q .lsiams_baseline
echo.
pause
goto :EOF


REM =====================================================================  SCAN
:SCAN
echo.
echo   ================================================
echo    L-SIAMS  -  scanning for what changed / broke
echo   ================================================
echo.

if not exist "%BASE%" (
    echo   [!] No memory of the working code exists yet.
    echo.
    echo       Run this FIRST, while the system still works:
    echo           find.bat save
    echo.
    echo       Then run  find.bat  again after something is changed.
    echo.
    pause
    goto :EOF
)
for /f "usebackq delims=" %%S in ("%BASE%\_saved_on.txt") do echo   Working copy remembered: %%S
echo.

set /a nParse=0, nDel=0, nMod=0, nAdd=0

REM ------------------------------------------------ 1) PHP SYNTAX (bugs) ------
echo   ------------------------------------------------------------------
echo    1. Checking every PHP file for syntax errors (bugs / broken edits)
echo   ------------------------------------------------------------------
if defined PHP (
    for %%R in (%ROOTS%) do (
        if exist "%CD%\%%R" (
            for /r "%CD%\%%R" %%F in (*.php) do call :LINT "%%F"
        )
    )
    if exist "%CD%\bootstrap.php" call :LINT "%CD%\bootstrap.php"
    if !nParse! EQU 0 echo   [OK] No PHP syntax errors found.
) else (
    echo   [skip] php.exe not found - cannot syntax-check.
)
echo.

REM ------------------------------ 2) COMPARE TO REMEMBERED WORKING CODE ------
echo   ------------------------------------------------------------------
echo    2. Comparing the whole codebase to the remembered working copy
echo   ------------------------------------------------------------------
for /r "%BASE%" %%F in (*) do (
    set "b=%%F"
    set "rel=!b:%BASE%\=!"
    if /I not "!rel!"=="_saved_on.txt" (
        set "cur=%CD%\!rel!"
        if not exist "!cur!" (
            set /a nDel+=1
            echo   [DELETED]  !rel!
        ) else (
            fc /b "%%F" "!cur!" >nul 2>&1
            if errorlevel 1 (
                set /a nMod+=1
                echo   [CHANGED]  !rel!
            )
        )
    )
)
REM extra files that are not in the remembered copy
for %%R in (%ROOTS%) do (
    if exist "%CD%\%%R" (
        for /r "%CD%\%%R" %%F in (*.php) do (
            set "c=%%F"
            set "rel=!c:%CD%\=!"
            if not exist "%BASE%\!rel!" (
                set /a nAdd+=1
                echo   [NEW/UNEXPECTED]  !rel!
            )
        )
    )
)
if !nDel! EQU 0 if !nMod! EQU 0 if !nAdd! EQU 0 echo   [OK] Code matches the remembered working copy exactly.
echo.

REM ------------------------------------------ 3) DATABASE CONNECTION ---------
echo   ------------------------------------------------------------------
echo    3. Testing the database connection
echo   ------------------------------------------------------------------
if exist "%BASE%\.env" if exist "%CD%\.env" (
    fc /b "%BASE%\.env" "%CD%\.env" >nul 2>&1
    if errorlevel 1 (
        echo   [!] Your .env is DIFFERENT from the remembered one.
        echo       These database lines are set right now:
        findstr /B /I /C:"DB_HOST" /C:"DB_PORT" /C:"DB_NAME" /C:"DB_USER" "%CD%\.env"
        echo       ^(remembered .env is in .lsiams_baseline\.env if you want to compare^)
    )
)
if defined PHP (
    if exist "%CD%\bin\env-check.php" (
        "%PHP%" "%CD%\bin\env-check.php" database 1>nul 2>"%TEMP%\lsiams_db.txt"
        if errorlevel 1 (
            echo   [X] Database check FAILED. Reason:
            for /f "usebackq delims=" %%L in ("%TEMP%\lsiams_db.txt") do echo        %%L
        ) else (
            echo   [OK] Database answered normally.
        )
        del "%TEMP%\lsiams_db.txt" 2>nul
    ) else (
        echo   [!] bin\env-check.php is missing - it may have been deleted.
        echo       Run  find.bat fix  to restore it, then test again.
    )
) else (
    echo   [skip] php.exe not found - cannot test the database.
)
echo.

REM --------------------------------------------------------- SUMMARY ---------
echo   ==================================================================
echo    SUMMARY:  !nParse! syntax error(s),  !nMod! changed,  !nDel! deleted,  !nAdd! unexpected
echo   ==================================================================
echo.
if !nParse! GTR 0 (
    echo   * SYNTAX ERROR = a bug or half-deleted code. The file and line are
    echo     shown above. Open that file at that line and fix the code, OR
    echo     restore the whole file with:   find.bat fix
    echo.
)
if !nMod! GTR 0 (
    echo   * CHANGED = the panel edited this file. To see WHAT changed line by
    echo     line:   fc ".lsiams_baseline\PATH" "PATH"
    echo     To put the working version back:   find.bat fix
    echo.
)
if !nDel! GTR 0 (
    echo   * DELETED = the panel removed this file. Restore it with:
    echo       find.bat fix
    echo.
)
if "%~1"=="" if !nMod! GTR 0 goto :OFFERDIFF
goto :ENDSCAN

:OFFERDIFF
set /p "SD=  Show the exact changed lines for every CHANGED file now? (Y/N) "
if /I "!SD!"=="Y" (
    for /r "%BASE%" %%F in (*) do (
        set "b=%%F"
        set "rel=!b:%BASE%\=!"
        if /I not "!rel!"=="_saved_on.txt" (
            set "cur=%CD%\!rel!"
            if exist "!cur!" (
                fc /b "%%F" "!cur!" >nul 2>&1
                if errorlevel 1 (
                    echo.
                    echo   ===== !rel! =====
                    echo   ^(left = remembered working code, right = current^)
                    fc "%%F" "!cur!"
                )
            )
        )
    )
)
:ENDSCAN
echo.
pause
goto :EOF


REM ==================================================================  :LINT
:LINT
"%PHP%" -l %1 1>nul 2>"%TEMP%\lsiams_lint.txt"
if errorlevel 1 (
    set /a nParse+=1
    set "lf=%~1"
    set "lf=!lf:%CD%\=!"
    echo   [SYNTAX ERROR]  !lf!
    for /f "usebackq tokens=* delims=" %%M in ("%TEMP%\lsiams_lint.txt") do echo        %%M
)
del "%TEMP%\lsiams_lint.txt" 2>nul
goto :EOF


REM ======================================================================  FIX
:FIX
echo.
echo   ================================================
echo    L-SIAMS  -  restoring the working code
echo   ================================================
echo.
if not exist "%BASE%" (
    echo   [!] No memory exists. Run  find.bat save  first ^(needs a working copy^).
    echo.
    pause
    goto :EOF
)

if not "%~2"=="" (
    REM restore a single file:  find.bat fix app\Core\Database.php
    set "one=%~2"
    if exist "%BASE%\!one!" (
        copy /y "%BASE%\!one!" "%CD%\!one!" >nul
        echo   [OK] Restored  !one!
    ) else (
        echo   [X] !one! is not in the remembered copy. Check the path shown by find.bat.
    )
    echo.
    pause
    goto :EOF
)

echo   This will overwrite CHANGED files and bring back DELETED ones, using the
echo   code remembered by  find.bat save . Unexpected NEW files are left alone.
set /p "GO=  Restore everything now? (Y/N) "
if /I not "!GO!"=="Y" (
    echo   Cancelled. Nothing changed.
    echo.
    pause
    goto :EOF
)

set /a nRes=0
for /r "%BASE%" %%F in (*) do (
    set "b=%%F"
    set "rel=!b:%BASE%\=!"
    if /I not "!rel!"=="_saved_on.txt" (
        set "cur=%CD%\!rel!"
        set "restore="
        if not exist "!cur!" set "restore=1"
        if exist "!cur!" ( fc /b "%%F" "!cur!" >nul 2>&1 || set "restore=1" )
        if defined restore (
            for %%D in ("!cur!") do if not exist "%%~dpD" mkdir "%%~dpD" 2>nul
            copy /y "%%F" "!cur!" >nul
            set /a nRes+=1
            echo   restored  !rel!
        )
    )
)
echo.
echo   [OK] Restored !nRes! file(s) to the remembered working version.
echo        Now run  find.bat  again - it should report everything clean.
echo.
pause
goto :EOF


REM =====================================================================  HELP
:HELP
echo.
echo   L-SIAMS find.bat - remember your code, then find and fix what changed
echo   ====================================================================
echo.
echo   STEP 1 (do this while the system WORKS):
echo       find.bat save
echo         Remembers a private copy of every PHP file, plus bootstrap.php,
echo         composer.json and .env, inside  .lsiams_baseline\
echo.
echo   STEP 2 (after the panel breaks something):
echo       find.bat
echo         - checks every PHP file for syntax errors (bugs), with file+line
echo         - compares all code to the remembered copy: CHANGED / DELETED / NEW
echo         - tests the database connection and prints the real reason if it
echo           fails (wrong DB_HOST/PORT/NAME/USER/PASS in .env, MySQL stopped)
echo.
echo   STEP 3 (fix it):
echo       Fix by hand using the file+line shown, then run find.bat again, OR
echo       find.bat fix                  restore ALL changed/deleted files
echo       find.bat fix app\Core\X.php   restore just one file
echo.
echo   HOW TO READ THE RESULTS:
echo     [SYNTAX ERROR] file:line   a bug / half-deleted code - open and fix,
echo                                or restore the file with find.bat fix
echo     [CHANGED]  path            edited - see the diff with:
echo                                  fc ".lsiams_baseline\path" "path"
echo     [DELETED]  path            removed - restore with find.bat fix
echo     [NEW/UNEXPECTED] path      a file that was not there originally
echo     Database FAILED ...        follow the printed reason; check .env
echo.
echo   The scan never changes anything. Only  find.bat fix  writes files.
echo.
pause
goto :EOF
