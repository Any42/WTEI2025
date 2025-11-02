@echo off
echo Starting K30RealtimeSync as Administrator...
echo.

REM Check if running as administrator
net session >nul 2>&1
if %errorLevel% == 0 (
    echo Running as Administrator - Good!
    echo.
    echo Starting K30RealtimeSync service...
    echo Device: 192.168.1.201:4370
    echo Web Server: http://localhost:8080
    echo.
    dotnet run
) else (
    echo Not running as Administrator. Restarting as Administrator...
    powershell -Command "Start-Process '%~f0' -Verb RunAs"
)