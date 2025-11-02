@echo off
echo Starting ZKTECO K30 Attendance Sync...
echo.

REM Check if config.json exists
if not exist "config.json" (
    echo Warning: config.json not found. Using default configuration.
    echo Please create config.json for custom settings.
    echo.
)

REM Check if executable exists
if not exist "bin\Debug\net472\ZKTest.exe" (
    echo Error: ZKTest.exe not found in bin\Debug\net472\
    echo Please build the project first.
    pause
    exit /b 1
)

REM Copy config.json to the executable directory if it doesn't exist
if not exist "bin\Debug\net472\config.json" (
    if exist "config.json" (
        echo Copying config.json to executable directory...
        copy "config.json" "bin\Debug\net472\"
    )
)

REM Run the application
echo Starting application...
bin\Debug\net472\ZKTest.exe

REM If we get here, the application has exited
echo.
echo Application has exited.
pause
