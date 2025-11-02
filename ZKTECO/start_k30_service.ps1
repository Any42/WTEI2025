# K30RealtimeSync Service Starter
Write-Host "Starting K30RealtimeSync Service..." -ForegroundColor Green
Write-Host ""
Write-Host "Device Configuration:" -ForegroundColor Yellow
Write-Host "- IP: 192.168.1.201" -ForegroundColor Cyan
Write-Host "- Port: 4370" -ForegroundColor Cyan
Write-Host "- Comm Key: 0" -ForegroundColor Cyan
Write-Host "- Device ID: 1" -ForegroundColor Cyan
Write-Host ""
Write-Host "Web Server: http://localhost:8080" -ForegroundColor Yellow
Write-Host ""

# Check if running as administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole] "Administrator")

if ($isAdmin) {
    Write-Host "Running as Administrator - Good!" -ForegroundColor Green
} else {
    Write-Host "WARNING: Not running as Administrator. Some features may not work properly." -ForegroundColor Red
    Write-Host "Please run this script as Administrator for best results." -ForegroundColor Red
    Write-Host ""
}

# Test device connectivity
Write-Host "Testing device connectivity..." -ForegroundColor Yellow
try {
    $ping = Test-Connection -ComputerName "192.168.1.201" -Count 1 -Quiet
    if ($ping) {
        Write-Host "✓ Device is reachable" -ForegroundColor Green
    } else {
        Write-Host "✗ Device is not reachable" -ForegroundColor Red
    }
} catch {
    Write-Host "✗ Error testing device connectivity: $($_.Exception.Message)" -ForegroundColor Red
}

# Test port connectivity
Write-Host "Testing port 4370..." -ForegroundColor Yellow
try {
    $tcpClient = New-Object System.Net.Sockets.TcpClient
    $connect = $tcpClient.BeginConnect("192.168.1.201", 4370, $null, $null)
    $wait = $connect.AsyncWaitHandle.WaitOne(3000, $false)
    if ($wait) {
        $tcpClient.EndConnect($connect)
        Write-Host "✓ Port 4370 is accessible" -ForegroundColor Green
        $tcpClient.Close()
    } else {
        Write-Host "✗ Port 4370 is not accessible" -ForegroundColor Red
    }
} catch {
    Write-Host "✗ Error testing port: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "Starting K30RealtimeSync..." -ForegroundColor Green
Write-Host "Press Ctrl+C to stop the service" -ForegroundColor Yellow
Write-Host ""

# Start the service
dotnet run
