# K30 Device Connection Diagnostic
Write-Host "=== K30 Device Connection Diagnostic ===" -ForegroundColor Green
Write-Host "Device IP: 192.168.1.201" -ForegroundColor Cyan
Write-Host "Device Port: 4370" -ForegroundColor Cyan
Write-Host ""

# Test 1: Basic network connectivity
Write-Host "1. Testing basic network connectivity..." -ForegroundColor Yellow
$ping = Test-NetConnection -ComputerName 192.168.1.201 -InformationLevel Quiet
if ($ping) {
    Write-Host "   ✅ Device is reachable on network" -ForegroundColor Green
} else {
    Write-Host "   ❌ Device is NOT reachable on network" -ForegroundColor Red
    Write-Host "   Check: Device power, network cable, IP address" -ForegroundColor Yellow
    exit 1
}

# Test 2: Port connectivity
Write-Host "`n2. Testing port 4370 connectivity..." -ForegroundColor Yellow
$portTest = Test-NetConnection -ComputerName 192.168.1.201 -Port 4370 -InformationLevel Quiet
if ($portTest) {
    Write-Host "   ✅ Port 4370 is open and responding" -ForegroundColor Green
} else {
    Write-Host "   ❌ Port 4370 is NOT responding" -ForegroundColor Red
    Write-Host "   This is the main issue!" -ForegroundColor Yellow
    Write-Host "   Possible causes:" -ForegroundColor Yellow
    Write-Host "   - Device is not in network mode" -ForegroundColor Yellow
    Write-Host "   - Device is busy with another operation" -ForegroundColor Yellow
    Write-Host "   - Device needs to be restarted" -ForegroundColor Yellow
    Write-Host "   - Firewall blocking the connection" -ForegroundColor Yellow
}

# Test 3: Web server status
Write-Host "`n3. Testing web server status..." -ForegroundColor Yellow
try {
    $response = Invoke-WebRequest -Uri "http://localhost:8080/status" -Method GET -TimeoutSec 5
    $status = $response.Content | ConvertFrom-Json
    Write-Host "   ✅ Web server is responding" -ForegroundColor Green
    Write-Host "   Device connected: $($status.deviceConnected)" -ForegroundColor $(if($status.deviceConnected) {"Green"} else {"Red"})
} catch {
    Write-Host "   ❌ Web server not responding: $($_.Exception.Message)" -ForegroundColor Red
}

# Test 4: Application process
Write-Host "`n4. Checking application process..." -ForegroundColor Yellow
$process = Get-Process -Name "ZKTest" -ErrorAction SilentlyContinue
if ($process) {
    Write-Host "   ✅ ZKTest application is running (PID: $($process.Id))" -ForegroundColor Green
} else {
    Write-Host "   ❌ ZKTest application is NOT running" -ForegroundColor Red
}

Write-Host "`n=== Diagnostic Complete ===" -ForegroundColor Green

if (-not $portTest) {
    Write-Host "`n🔧 SOLUTION: Port 4370 is not responding" -ForegroundColor Yellow
    Write-Host "Try these steps:" -ForegroundColor Cyan
    Write-Host "1. Restart your K30 device (power off/on)" -ForegroundColor White
    Write-Host "2. Check device menu: Menu → Comm → TCP/IP → Make sure it's enabled" -ForegroundColor White
    Write-Host "3. On device: Menu → Comm → TCP/IP → Test connection" -ForegroundColor White
    Write-Host "4. Check if device is in 'User' mode (not 'Admin' mode)" -ForegroundColor White
    Write-Host "5. Try connecting from device menu: Menu → Comm → Connect" -ForegroundColor White
}
