@echo off
echo Starting ZKTECO K30 Attendance Sync with Administrator privileges...
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

echo.

REM Check if config.json exists
if not exist "config.json" (
    echo Warning: config.json not found. Using default configuration.
    echo Please create config.json for custom settings.
    echo.
)

REM Check if executable exists
if not exist "bin\Debug\ZKTest.exe" (
    echo Error: ZKTest.exe not found in bin\Debug\
    echo Please build the project first.
    pause
    exit /b 1
)

REM Test network connectivity first
echo Testing device connectivity...
powershell -ExecutionPolicy Bypass -File "test-connection.ps1"
echo.

REM Run the application
echo Starting application...
echo Press Ctrl+C to stop the application
echo.
bin\Debug\ZKTest.exe

REM If we get here, the application has exited
echo.
echo Application has exited.
pause
