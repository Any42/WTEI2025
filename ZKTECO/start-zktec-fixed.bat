@echo off
echo Starting ZKTECO K30 Attendance Sync...
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running with Administrator privileges - OK
) else (
    echo Requesting Administrator privileges...
    powershell -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
    exit /b
)

REM Stop any existing instance
echo Stopping any existing ZKTest processes...
taskkill /F /IM ZKTest.exe >nul 2>&1

REM Wait a moment
timeout /t 3 /nobreak >nul

REM Check if executable exists
if not exist "bin\Debug\ZKTest.exe" (
    echo Error: ZKTest.exe not found in bin\Debug\
    echo Please build the project first.
    echo.
    echo Press any key to exit...
    pause >nul
    exit /b 1
)

REM Start the application with full path
echo Starting ZKTest application...
echo.
echo The application will start in a new window.
echo Look for connection messages in that window.
echo.

REM Use full path to avoid path issues
cd /d "%~dp0"
start "ZKTest - K30 Attendance Sync" "bin\Debug\ZKTest.exe"

echo.
echo Application started!
echo.
echo To test enrollment:
echo 1. Wait 10 seconds for application to start
echo 2. Run: powershell -ExecutionPolicy Bypass -File "test-enhanced-enrollment-fixed.ps1"
echo.
echo Press any key to exit...
pause >nul
