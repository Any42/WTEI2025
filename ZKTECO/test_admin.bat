@echo off
echo Testing Administrator privileges...
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo SUCCESS: Running as Administrator!
    echo You have Administrator privileges.
    echo.
    echo Now you can run the K30RealtimeSync service.
    echo.
    pause
) else (
    echo FAILED: Not running as Administrator.
    echo.
    echo To fix this:
    echo 1. Right-click on this file
    echo 2. Select "Run as administrator"
    echo 3. Click "Yes" when prompted
    echo.
    echo OR
    echo.
    echo 1. Press Windows + X
    echo 2. Select "Windows PowerShell (Admin)"
    echo 3. Navigate to: cd C:\xampp\htdocs\WTEI\ZKTECO
    echo 4. Run: dotnet run
    echo.
    pause
)
