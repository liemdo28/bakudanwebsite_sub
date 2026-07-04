@echo off
setlocal enabledelayedexpansion
set "OUT=%~dp0.ga4-evidence"
if not exist "%OUT%" mkdir "%OUT%"

echo ============================================================ > "%OUT%\ga4-realtime-ping.txt"
echo  GA4 MEASUREMENT PROTOCOL page_view PING
echo  Measurement ID: G-3GZ2RYDR6M
echo  Generated: %DATE% %TIME%
echo ============================================================ >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM Required params for a GA4 page_view hit:
REM   v=2              Measurement Protocol version
REM   tid=G-3GZ2RYDR6M  GA4 Measurement ID
REM   cid=<client_id>   Random anonymous client ID (1-150 char UUID-like)
REM   en=page_view      Event name
REM   dl=<location>     Document location (URL)
REM   dt=<title>        Document title
REM   sid=<session_id>  Session ID (millis timestamp)
REM   _s=1              Session hit counter

set "URL_BASE=https://www.google-analytics.com/g/collect"
set "TID=G-3GZ2RYDR6M"

REM ---------- PING 1 : HOMEPAGE ----------
set "CID1=11111111-aaaa-bbbb-cccc-222222222222"
set "SID1=1700000000001"
set "PING1=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID1%^&en=page_view^&dl=https://bakudanramen.com/^&dt=Best%20Ramen%20in%20San%20Antonio%20|%20Bakudan%20Ramen%20--%203%20Locations^&sid=%SID1%^&_s=1^&seg=1"
echo [PING 1] HOMEPAGE: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING1! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING1!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 2 : MENU ----------
set "CID2=22222222-aaaa-bbbb-cccc-333333333333"
set "SID2=1700000000002"
set "PING2=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID2%^&en=page_view^&dl=https://bakudanramen.com/menu.html^&dt=Menu%20-%20Bakudan%20Ramen%20|%20Authentic%20Japanese%20Ramen%20in%20San%20Antonio^&sid=%SID2%^&_s=1"
echo [PING 2] MENU: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING2! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING2!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 3 : LOCATIONS ----------
set "CID3=33333333-aaaa-bbbb-cccc-444444444444"
set "SID3=1700000000003"
set "PING3=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID3%^&en=page_view^&dl=https://bakudanramen.com/locations.html^&dt=Locations%20-%20Bakudan%20Ramen^&sid=%SID3%^&_s=1"
echo [PING 3] LOCATIONS: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING3! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING3!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 4 : ORDER ----------
set "CID4=44444444-aaaa-bbbb-cccc-555555555555"
set "SID4=1700000000004"
set "PING4=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID4%^&en=page_view^&dl=https://bakudanramen.com/order.html^&dt=Order%20Bakudan%20Ramen%20|%20Pickup%20%26%20Delivery%20in%20San%20Antonio^&sid=%SID4%^&_s=1"
echo [PING 4] ORDER: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING4! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING4!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 5 : FUNDRAISER (Catering) ----------
set "CID5=55555555-aaaa-bbbb-cccc-666666666666"
set "SID5=1700000000005"
set "PING5=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID5%^&en=page_view^&dl=https://bakudanramen.com/fundraiser.html^&dt=Fundraiser%20Program%20-%20Bakudan%20Ramen^&sid=%SID5%^&_s=1"
echo [PING 5] CATERING/FUNDRAISER: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING5! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING5!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 6 : ABOUT (Rewards / Loyalty proxy) ----------
set "CID6=66666666-aaaa-bbbb-cccc-777777777777"
set "SID6=1700000000006"
set "PING6=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID6%^&en=page_view^&dl=https://bakudanramen.com/about.html^&dt=Our%20Story%20-%20Bakudan%20Ramen%20|%20Rewards%20%26%20Loyalty^&sid=%SID6%^&_s=1"
echo [PING 6] REWARDS/LOYALTY (about): >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING6! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING6!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

REM ---------- PING 7 : CONTACT (about / blog contact form) ----------
set "CID7=77777777-aaaa-bbbb-cccc-888888888888"
set "SID7=1700000000007"
set "PING7=%URL_BASE%?v=2^&tid=%TID%^&cid=%CID7%^&en=page_view^&dl=https://bakudanramen.com/blog.html^&dt=Blog%20-%20Contact%20Bakudan%20Ramen^&sid=%SID7%^&_s=1"
echo [PING 7] CONTACT/BLOG: >> "%OUT%\ga4-realtime-ping.txt"
echo   URL : !PING7! >> "%OUT%\ga4-realtime-ping.txt"
curl -s -o nul -w "   STATUS: HTTP/%%{http_code}  BYTES: %%{size_download}" "!PING7!" >> "%OUT%\ga4-realtime-ping.txt"
echo. >> "%OUT%\ga4-realtime-ping.txt"

echo ============================================================ >> "%OUT%\ga4-realtime-ping.txt"
echo ALL 7 PAGES SENT. HTTP 204 = SUCCESS (no content).
echo These page_view hits should now appear in GA4 Realtime:
echo   reports > Realtime  (within 30 seconds)
echo ============================================================ >> "%OUT%\ga4-realtime-ping.txt"
type "%OUT%\ga4-realtime-ping.txt"
endlocal
