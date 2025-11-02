@echo off
:: Stop K30 Service
:: Run this as Administrator if normal stop fails

echo ============================================
echo Stopping K30 Service
echo ============================================

echo.
echo Checking for running ZKTest.exe...
tasklist | findstr ZKTest.exe

if %errorlevel% neq 0 (
    echo.
    echo [OK] No ZKTest.exe is running
    pause
    exit /b 0
)

echo.
echo Attempting to stop ZKTest.exe...
taskkill /F /IM ZKTest.exe

if %errorlevel% equ 0 (
    echo.
    echo [SUCCESS] ZKTest.exe stopped!
    echo.
    echo You can now:
    echo 1. Rebuild in Visual Studio
    echo 2. Run the new ZKTest.exe
    echo.
) else (
    echo.
    echo [ERROR] Could not stop ZKTest.exe
    echo.
    echo Solutions:
    echo 1. Close the ZKTest.exe console window manually
    echo 2. Right-click this script and "Run as Administrator"
    echo 3. Open Task Manager and end ZKTest.exe
    echo.
)

pause

