@echo off
echo ========================================
echo ZKTECO K30 Attendance Sync Startup
echo ========================================
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo [OK] Running with Administrator privileges
) else (
    echo [INFO] Requesting Administrator privileges...
    echo This is required for the web server to bind to port 8080
    powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

echo.
echo [INFO] Stopping any existing ZKTest processes...
taskkill /F /IM ZKTest.exe >nul 2>&1

echo [INFO] Waiting for processes to stop...
timeout /t 3 /nobreak >nul

REM Check if executable exists
if not exist "bin\Debug\ZKTest.exe" (
    echo [ERROR] ZKTest.exe not found in bin\Debug\
    echo Please build the project first.
    echo.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

echo [OK] ZKTest.exe found
echo.
echo [INFO] Starting ZKTECO K30 Attendance Sync...
echo.
echo The application will start in a new window.
echo Look for these messages in that window:
echo   - "Successfully connected to K30 at 192.168.1.201:4370"
echo   - "Web server started on http://localhost:8080"
echo.

REM Use full path and start in new window
cd /d "%~dp0"
start "ZKTest - K30 Attendance Sync" "bin\Debug\ZKTest.exe"

echo [OK] Application started!
echo.
echo ========================================
echo Next Steps:
echo ========================================
echo 1. Wait 10-15 seconds for application to fully start
echo 2. Check the ZKTest window for connection status
echo 3. Test enrollment with:
echo    powershell -ExecutionPolicy Bypass -File "test-enhanced-enrollment-fixed.ps1"
echo.
echo ========================================
echo Troubleshooting:
echo ========================================
echo If you see "Access is denied" error:
echo - Make sure you're running as Administrator
echo - Check if port 8080 is already in use
echo.
echo If you see "Device connection error":
echo - Check if K30 device is powered on
echo - Verify device IP in config.json (currently: 192.168.1.201)
echo - Ensure device is on the same network
echo.
echo Press any key to exit...
pause >nul
