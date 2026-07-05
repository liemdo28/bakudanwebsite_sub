@echo off
setlocal enabledelayedexpansion
set "ROOT=%~dp0"
set "OUT=%ROOT%.ga4-evidence"
if not exist "%OUT%" mkdir "%OUT%"

echo ============================================================ > "%OUT%\source-code-audit.txt"
echo  BAKUDAN GA4 SOURCE-CODE AUDIT - MEASUREMENT ID: G-3GZ2RYDR6M >> "%OUT%\source-code-audit.txt"
echo  Generated: %DATE% %TIME% >> "%OUT%\source-code-audit.txt"
echo  Working dir: %ROOT% >> "%OUT%\source-code-audit.txt"
echo ============================================================ >> "%OUT%\source-code-audit.txt"

set TOTAL=0
set WITH=0
set MISSING=0
set MISSING_LIST=

for /r "%ROOT%" %%F in (*.html) do (
    set /a TOTAL+=1
    findstr /C:"G-3GZ2RYDR6M" "%%F" >nul
    if !errorlevel! equ 0 (
        set /a WITH+=1
        echo [OK]    %%~fF >> "%OUT%\source-code-audit.txt"
    ) else (
        set /a MISSING+=1
        set "MISSING_LIST=!MISSING_LIST! %%~fF"
        echo [MISS]  %%~fF >> "%OUT%\source-code-audit.txt"
    )
)

echo. >> "%OUT%\source-code-audit.txt"
echo ----------------------------------------------------------- >> "%OUT%\source-code-audit.txt"
echo TOTAL HTML FILES SCANNED:  %TOTAL% >> "%OUT%\source-code-audit.txt"
echo FILES WITH GA4 TAG:       %WITH% >> "%OUT%\source-code-audit.txt"
echo FILES MISSING GA4 TAG:    %MISSING% >> "%OUT%\source-code-audit.txt"
echo. >> "%OUT%\source-code-audit.txt"
if %MISSING% gtr 0 (
    echo MISSING FILES: >> "%OUT%\source-code-audit.txt"
    echo %MISSING_LIST% >> "%OUT%\source-code-audit.txt"
)

echo.
echo ===== AUDIT COMPLETE =====
echo Total: %TOTAL%  WithTag: %WITH%  Missing: %MISSING%
echo Evidence written to: %OUT%\source-code-audit.txt
endlocal
