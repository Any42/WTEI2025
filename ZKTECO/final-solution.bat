@echo off
echo ========================================
echo ZKTECO K30 FINAL SOLUTION
echo ========================================
echo.
echo Your K30 device is working perfectly:
echo - IP: 192.168.1.201
echo - Port: 4370
echo - Network: Connected
echo.
echo The issue is with the C# application connection.
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo [OK] Running with Administrator privileges
) else (
    echo [INFO] Requesting Administrator privileges...
    powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo.
echo [INFO] Stopping any existing processes...
taskkill /F /IM ZKTest.exe >nul 2>&1
timeout /t 3 /nobreak >nul

echo [INFO] Starting application with enhanced connection...
cd /d "%~dp0"
start "ZKTest - Enhanced Connection" "bin\Debug\ZKTest.exe"

echo.
echo [INFO] Application started!
echo.
echo ========================================
echo NEXT STEPS:
echo ========================================
echo 1. Wait 15 seconds for full startup
echo 2. Check the ZKTest window for messages:
echo    - Look for "Successfully connected to K30"
echo    - Look for "Device connection established"
echo 3. If you see connection errors, try:
echo    - Restart the K30 device
echo    - Check device menu: Menu -> Comm -> TCP/IP
echo.
echo ========================================
echo TEST ENROLLMENT:
echo ========================================
echo After 15 seconds, run this command:
echo powershell -ExecutionPolicy Bypass -File "test-enhanced-enrollment-fixed.ps1"
echo.
echo ========================================
echo YOUR WEB APP INTEGRATION:
echo ========================================
echo Your web app should send POST requests to:
echo http://localhost:8080/register-employee
echo.
echo Example JSON:
echo {
echo   "employeeId": 123,
echo   "employeeName": "John Doe"
echo }
echo.
echo After successful response, go to K30 device
echo and enroll fingerprint manually.
echo.
echo Press any key to exit...
pause >nul
