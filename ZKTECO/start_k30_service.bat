@echo off
echo Starting K30RealtimeSync Service...
echo.
echo Device Configuration:
echo - IP: 192.168.1.201
echo - Port: 4370
echo - Comm Key: 0
echo - Device ID: 1
echo.
echo Web Server: http://localhost:8080
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running as Administrator - Good!
) else (
    echo WARNING: Not running as Administrator. Some features may not work properly.
    echo Please run this script as Administrator for best results.
    echo.
)

REM Start the K30RealtimeSync service
echo Starting K30RealtimeSync...
dotnet run

pause
