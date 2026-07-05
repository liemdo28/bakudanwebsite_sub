@echo off
setlocal enabledelayedexpansion
set "ROOT=%~dp0"
set "OUT=%ROOT%.ga4-evidence"
if not exist "%OUT%" mkdir "%OUT%"

echo ============================================================ > "%OUT%\ga4-head-extract.txt"
echo  GA4 TAG HEAD EXTRACTION (verbatim source)
echo  Measurement ID: G-3GZ2RYDR6M
echo  Generated: %DATE% %TIME%
echo ============================================================ >> "%OUT%\ga4-head-extract.txt"
echo. >> "%OUT%\ga4-head-extract.txt"

set PAGES=index.html menu.html locations.html order.html happy-hour.html fundraiser.html about.html blog.html
for %%P in (%PAGES%) do (
    if exist "%ROOT%%%P" (
        echo ################ FILE: %%P ################ >> "%OUT%\ga4-head-extract.txt"
        echo --- LINE 1 to 15 (HEAD region) --- >> "%OUT%\ga4-head-extract.txt"
        for /f "skip=0 delims=" %%L in ('findstr /N "^" "%ROOT%%%P"') do (
            set "LN=%%L"
            for /f "tokens=1 delims=:" %%N in ("%%L") do (
                if %%N leq 15 (
                    echo %%L >> "%OUT%\ga4-head-extract.txt"
                )
            )
        )
        echo. >> "%OUT%\ga4-head-extract.txt"
    ) else (
        echo [SKIP] %%P - FILE NOT FOUND >> "%OUT%\ga4-head-extract.txt"
        echo. >> "%OUT%\ga4-head-extract.txt"
    )
)

echo ============================================================ >> "%OUT%\ga4-head-extract.txt"
echo HEAD EXTRACTION COMPLETE
echo ============================================================ >> "%OUT%\ga4-head-extract.txt"
type "%OUT%\ga4-head-extract.txt"
endlocal
