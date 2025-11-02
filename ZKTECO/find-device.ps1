# Find ZKTECO K30 Device on Network
Write-Host "=== Finding ZKTECO K30 Device ===" -ForegroundColor Green

# Get local network range
$localIP = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.IPAddress -like "192.168.*" -or $_.IPAddress -like "10.*" -or $_.IPAddress -like "172.*"} | Select-Object -First 1).IPAddress
$networkRange = $localIP -replace '\.\d+$', ''

Write-Host "Scanning network range: $networkRange.1-254" -ForegroundColor Yellow
Write-Host "Testing port 4370 (ZKTECO default port)..." -ForegroundColor Yellow

$foundDevices = @()

# Scan common IP ranges
$ranges = @("192.168.1", "192.168.0", "10.0.0", "172.16.0")
foreach ($range in $ranges) {
    Write-Host "`nScanning $range.x..." -ForegroundColor Cyan
    for ($i = 1; $i -le 254; $i++) {
        $ip = "$range.$i"
        $result = Test-NetConnection -ComputerName $ip -Port 4370 -InformationLevel Quiet -WarningAction SilentlyContinue
        if ($result) {
            Write-Host "✅ Found device at $ip:4370" -ForegroundColor Green
            $foundDevices += $ip
        }
    }
}

if ($foundDevices.Count -gt 0) {
    Write-Host "`n🎉 Found $($foundDevices.Count) device(s):" -ForegroundColor Green
    foreach ($device in $foundDevices) {
        Write-Host "  - $device:4370" -ForegroundColor Cyan
    }
    Write-Host "`nUpdate your config.json with one of these IP addresses." -ForegroundColor Yellow
} else {
    Write-Host "`n❌ No ZKTECO devices found on common network ranges." -ForegroundColor Red
    Write-Host "Please check:" -ForegroundColor Yellow
    Write-Host "  1. Device is powered on" -ForegroundColor Yellow
    Write-Host "  2. Device is connected to network" -ForegroundColor Yellow
    Write-Host "  3. Device IP is in a different range" -ForegroundColor Yellow
    Write-Host "  4. Device uses a different port" -ForegroundColor Yellow
}

Write-Host "`n=== Scan Complete ===" -ForegroundColor Green
