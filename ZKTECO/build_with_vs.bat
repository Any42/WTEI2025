@echo off
REM ============================================
REM Build using Visual Studio Developer Console
REM ============================================
echo.
echo ============================================
echo Building K30 Service with Visual Studio
echo ============================================
echo.

cd /d "%~dp0"

REM Try to find and use Visual Studio Developer Command Prompt
set "VSWHERE=%ProgramFiles(x86)%\Microsoft Visual Studio\Installer\vswhere.exe"

if exist "%VSWHERE%" (
    echo Finding Visual Studio installation...
    for /f "usebackq tokens=*" %%i in (`"%VSWHERE%" -latest -products * -requires Microsoft.Component.MSBuild -property installationPath`) do (
        set "VS_PATH=%%i"
    )
    
    if defined VS_PATH (
        echo Found Visual Studio at: !VS_PATH!
        
        REM Use VS Developer Command Prompt
        if exist "!VS_PATH!\Common7\Tools\VsDevCmd.bat" (
            call "!VS_PATH!\Common7\Tools\VsDevCmd.bat"
            
            echo.
            echo Cleaning old build...
            if exist "bin\Release" rmdir /s /q "bin\Release"
            if exist "obj\Release" rmdir /s /q "obj\Release"
            
            echo.
            echo Building project...
            msbuild ZKTest.csproj /t:Build /p:Configuration=Release /verbosity:minimal
            
            if %errorLevel% equ 0 (
                echo.
                echo ============================================
                echo BUILD SUCCESSFUL!
                echo ============================================
                echo.
                echo Executable location:
                echo %~dp0bin\Release\ZKTest.exe
                echo.
                echo You can now run:
                echo   bin\Release\ZKTest.exe
                echo.
                pause
                exit /b 0
            ) else (
                echo.
                echo ============================================
                echo BUILD FAILED!
                echo ============================================
                pause
                exit /b 1
            )
        )
    )
)

echo.
echo ERROR: Could not find Visual Studio installation
echo.
echo Please try one of these options:
echo.
echo OPTION 1: Use Visual Studio GUI
echo   1. Double-click: ZKTest.sln
echo   2. Set Configuration to "Release" 
echo   3. Click Build ^> Build Solution
echo.
echo OPTION 2: Use the fixed rebuild script
echo   Run: rebuild_and_test_fixed.bat
echo.
pause

