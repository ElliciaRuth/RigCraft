@echo off
REM ------------------------------------------------------------------
REM  RigCraft - serve the app to phones on your Wi-Fi/LAN.
REM
REM  Uses XAMPP's PHP built-in web server on port 8080, so it works
REM  alongside Apache (MySQL from XAMPP must still be running).
REM  Open the intake page via one of the LAN addresses below on this PC,
REM  and the QR codes it generates will point to a phone-reachable URL.
REM
REM  Serves the built app (frontend\dist). After changing the Vue code,
REM  run "npm run build" in the frontend folder first.
REM ------------------------------------------------------------------
set PORT=8080
set PHP=C:\xampp\php\php.exe

echo.
echo  RigCraft LAN server - open one of these on this PC:
for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /c:"IPv4"') do (
  for /f "tokens=*" %%B in ("%%A") do echo     http://%%B:%PORT%/RigCraft/
)
echo.
echo  (Allow "PHP" through Windows Firewall on Private networks if asked.)
echo  Press Ctrl+C to stop.
echo.
"%PHP%" -S 0.0.0.0:%PORT% -t "%~dp0.." "%~dp0dev-router.php"
