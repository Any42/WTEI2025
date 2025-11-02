# Simple K30RealtimeSync Starter (No Admin Required)
Write-Host "K30RealtimeSync Simple Starter" -ForegroundColor Green
Write-Host "================================" -ForegroundColor Green
Write-Host ""

# Check current directory
Write-Host "Current Directory: $(Get-Location)" -ForegroundColor Yellow

# Check if we're in the right directory
if (Test-Path "Program.cs") {
    Write-Host "✓ Found Program.cs - We're in the right directory" -ForegroundColor Green
} else {
    Write-Host "✗ Program.cs not found - Wrong directory!" -ForegroundColor Red
    Write-Host "Please navigate to: C:\xampp\htdocs\WTEI\ZKTECO" -ForegroundColor Yellow
    exit 1
}

# Check if dotnet is available
Write-Host "Checking .NET SDK..." -ForegroundColor Yellow
try {
    $dotnetVersion = dotnet --version
    Write-Host "✓ .NET SDK Version: $dotnetVersion" -ForegroundColor Green
} catch {
    Write-Host "✗ .NET SDK not found or not working" -ForegroundColor Red
    Write-Host "Please install .NET SDK from: https://dotnet.microsoft.com/download" -ForegroundColor Yellow
    exit 1
}

# Check if project builds
Write-Host "Building project..." -ForegroundColor Yellow
try {
    dotnet build --verbosity quiet
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Project builds successfully" -ForegroundColor Green
    } else {
        Write-Host "✗ Project build failed" -ForegroundColor Red
        exit 1
    }
} catch {
    Write-Host "✗ Build error: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# Test device connectivity
Write-Host "Testing device connectivity..." -ForegroundColor Yellow
try {
    $ping = Test-Connection -ComputerName "192.168.1.201" -Count 1 -Quiet
    if ($ping) {
        Write-Host "✓ K30 Device (192.168.1.201) is reachable" -ForegroundColor Green
    } else {
        Write-Host "✗ K30 Device (192.168.1.201) is not reachable" -ForegroundColor Red
        Write-Host "Please check device connection and IP address" -ForegroundColor Yellow
    }
} catch {
    Write-Host "✗ Error testing device: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "Starting K30RealtimeSync..." -ForegroundColor Green
Write-Host "Note: If you get 'Access denied' errors, you need to run as Administrator" -ForegroundColor Yellow
Write-Host "Press Ctrl+C to stop the service" -ForegroundColor Yellow
Write-Host ""

# Start the service
try {
    dotnet run
} catch {
    Write-Host "Error starting service: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host ""
    Write-Host "If you see Access denied errors:" -ForegroundColor Yellow
    Write-Host "1. Right-click PowerShell and select Run as administrator" -ForegroundColor White
    Write-Host "2. Or right-click run_as_admin.bat and select Run as administrator" -ForegroundColor White
}
