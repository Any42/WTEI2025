@echo off
REM ============================================
REM K30 Service - Rebuild and Test Script
REM ============================================
echo.
echo ============================================
echo K30 Enrollment Service - Rebuild and Test
echo ============================================
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo ERROR: This script must be run as Administrator
    echo Right-click and select "Run as administrator"
    echo.
    pause
    exit /b 1
)

echo Step 1: Cleaning old build...
cd /d "%~dp0"
if exist "bin\Release" rmdir /s /q "bin\Release"
if exist "obj\Release" rmdir /s /q "obj\Release"
echo   Done.
echo.

echo Step 2: Building C# service...
msbuild ZKTest.csproj /t:Build /p:Configuration=Release /verbosity:minimal
if %errorLevel% neq 0 (
    echo.
    echo ERROR: Build failed! Check errors above.
    echo.
    pause
    exit /b 1
)
echo   Build successful!
echo.

echo Step 3: Checking if service is already running...
tasklist /FI "IMAGENAME eq ZKTest.exe" 2>NUL | find /I /N "ZKTest.exe">NUL
if "%ERRORLEVEL%"=="0" (
    echo   Service is running. Stopping it...
    taskkill /F /IM ZKTest.exe >nul 2>&1
    timeout /t 2 >nul
    echo   Service stopped.
) else (
    echo   Service is not running.
)
echo.

echo Step 4: Starting service in new window...
start "K30 Enrollment Service" /D "%~dp0bin\Release" ZKTest.exe
timeout /t 3 >nul
echo   Service started in separate window.
echo.

echo Step 5: Waiting for service to initialize...
timeout /t 5 >nul
echo   Service should be ready now.
echo.

echo Step 6: Testing connection...
echo   Opening test page in browser...
start http://localhost/WTEI/ZKTECO/test_connection.php
echo.

echo ============================================
echo INSTRUCTIONS:
echo ============================================
echo 1. Check the K30 service window for logs
echo 2. The test page should open in your browser
echo 3. Review test results
echo.
echo If tests pass:
echo   - Go to AdminEmployees.php
echo   - Try enrolling an employee
echo   - Check C# console for "HTTP REQUEST RECEIVED"
echo.
echo If tests fail:
echo   - Check firewall settings
echo   - Verify port 8888 is not in use
echo   - Review HTTP_CONNECTION_FIX.md
echo.
echo ============================================
echo.
echo Press any key to exit...
pause >nul

