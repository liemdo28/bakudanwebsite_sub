@echo off
setlocal
set "OUT=%~dp0.ga4-evidence"
if not exist "%OUT%" mkdir "%OUT%"

echo ============================================================ > "%OUT%\ga4-endpoint-probe.txt"
echo  GA4 ENDPOINT PROBE - G-3GZ2RYDR6M
echo  Generated: %DATE% %TIME%
echo ============================================================
echo. >> "%OUT%\ga4-endpoint-probe.txt"

echo [1/3] Probing Google Tag Manager gtag.js endpoint... >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  URL: https://www.googletagmanager.com/gtag/js?id=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "https://www.googletagmanager.com/gtag/js?id=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"

echo [2/3] Probing Google Analytics 4 Measurement Protocol endpoint... >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  URL: https://www.google-analytics.com/g/collect?v=2^&tid=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "https://www.google-analytics.com/g/collect?v=2&tid=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"

echo [3/3] Probing Google Analytics 4 alternate endpoint... >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  URL: https://analytics.google.com/g/collect?v=2^&tid=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
curl -s -o nul -w "  STATUS: HTTP/%%{http_code}" "https://analytics.google.com/g/collect?v=2&tid=G-3GZ2RYDR6M" >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"
echo. >> "%OUT%\ga4-endpoint-probe.txt"

echo ============================================================ >> "%OUT%\ga4-endpoint-probe.txt"
echo ENDPOINT PROBE COMPLETE
echo ============================================================ >> "%OUT%\ga4-endpoint-probe.txt"

echo.
type "%OUT%\ga4-endpoint-probe.txt"
endlocal
