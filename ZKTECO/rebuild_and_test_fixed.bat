@echo off
REM ============================================
REM K30 Service - Rebuild and Test Script
REM Auto-detects MSBuild location
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

echo Step 1: Finding MSBuild...
cd /d "%~dp0"

REM Try to find MSBuild in common locations
set MSBUILD_PATH=

REM Check Visual Studio 2022
if exist "C:\Program Files\Microsoft Visual Studio\2022\Community\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files\Microsoft Visual Studio\2022\Community\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2022 Community
    goto :build
)
if exist "C:\Program Files\Microsoft Visual Studio\2022\Professional\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files\Microsoft Visual Studio\2022\Professional\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2022 Professional
    goto :build
)
if exist "C:\Program Files\Microsoft Visual Studio\2022\Enterprise\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files\Microsoft Visual Studio\2022\Enterprise\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2022 Enterprise
    goto :build
)

REM Check Visual Studio 2019
if exist "C:\Program Files (x86)\Microsoft Visual Studio\2019\Community\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files (x86)\Microsoft Visual Studio\2019\Community\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2019 Community
    goto :build
)
if exist "C:\Program Files (x86)\Microsoft Visual Studio\2019\Professional\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files (x86)\Microsoft Visual Studio\2019\Professional\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2019 Professional
    goto :build
)
if exist "C:\Program Files (x86)\Microsoft Visual Studio\2019\Enterprise\MSBuild\Current\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files (x86)\Microsoft Visual Studio\2019\Enterprise\MSBuild\Current\Bin\MSBuild.exe"
    echo   Found: Visual Studio 2019 Enterprise
    goto :build
)

REM Check MSBuild standalone (older versions)
if exist "C:\Program Files (x86)\MSBuild\14.0\Bin\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Program Files (x86)\MSBuild\14.0\Bin\MSBuild.exe"
    echo   Found: MSBuild 14.0
    goto :build
)

REM Check .NET Framework locations
if exist "C:\Windows\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe" (
    set "MSBUILD_PATH=C:\Windows\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe"
    echo   Found: .NET Framework 4.0
    goto :build
)

REM MSBuild not found - provide instructions
echo.
echo ============================================
echo ERROR: MSBuild not found!
echo ============================================
echo.
echo MSBuild is required to compile the C# service.
echo.
echo OPTION 1 - Use Visual Studio (Recommended):
echo   1. Open Visual Studio
echo   2. Open: ZKTECO\ZKTest.sln
echo   3. Set Configuration to "Release"
echo   4. Click Build ^> Build Solution
echo   5. Run: ZKTECO\bin\Release\ZKTest.exe
echo.
echo OPTION 2 - Install Build Tools:
echo   Download from: https://visualstudio.microsoft.com/downloads/
echo   Install: "Build Tools for Visual Studio"
echo   Select: ".NET desktop build tools"
echo.
echo OPTION 3 - Use pre-compiled version:
echo   If you already built it before, just run:
echo   ZKTECO\bin\Release\ZKTest.exe
echo.
echo ============================================
pause
exit /b 1

:build
echo   MSBuild found!
echo.

echo Step 2: Cleaning old build...
if exist "bin\Release" rmdir /s /q "bin\Release"
if exist "obj\Release" rmdir /s /q "obj\Release"
echo   Done.
echo.

echo Step 3: Building C# service...
"%MSBUILD_PATH%" ZKTest.csproj /t:Build /p:Configuration=Release /verbosity:minimal /nologo
if %errorLevel% neq 0 (
    echo.
    echo ERROR: Build failed! Check errors above.
    echo.
    pause
    exit /b 1
)
echo   Build successful!
echo.

echo Step 4: Checking if service is already running...
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

echo Step 5: Starting service in new window...
start "K30 Enrollment Service" /D "%~dp0bin\Release" ZKTest.exe
timeout /t 3 >nul
echo   Service started in separate window.
echo.

echo Step 6: Waiting for service to initialize...
timeout /t 5 >nul
echo   Service should be ready now.
echo.

echo Step 7: Testing connection...
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

