# =================================================
# Deploy Website Files to narayanakarimunjawa.com
# via FTP - Upload public/ folder to hosting
# =================================================

$ftpHost = "ftp://ftp.adfsystem.online"
$ftpUser = "adfb2574"
$ftpPass = "mDQmDQtfK3P5xXe74"

# Local source: public/ folder in this repo
$localBase = "C:\xampp\htdocs\narayanakarimunjawa\adf_sytem\public"
# Remote destination: narayanakarimunjawa.com website folder
$remoteBase = "/home/adfb2574/public_html/narayanakarimunjawa.com"

# Also deploy developer/web-settings.php to adf_system
$deployAdfSystem = $true
$localAdfBase = "C:\xampp\htdocs\narayanakarimunjawa\adf_sytem"
$remoteAdfBase = "/home/adfb2574/public_html/adf_system"

# Files to deploy to website (narayanakarimunjawa.com)
$webFiles = @(
    "index.php",
    "includes/footer.php",
    "includes/config.php",
    "includes/database.php",
    "includes/header.php",
    "assets/css/homepage.css",
    "assets/css/website.css",
    "assets/js/main.js",
    "booking.php"
)

# Files to deploy to adf_system
$adfFiles = @(
    "developer/web-settings.php"
)

# ===========================================
# FTP Upload Function
# ===========================================
function Upload-FtpFile {
    param (
        [string]$LocalPath,
        [string]$RemotePath
    )
    
    if (-not (Test-Path $LocalPath)) {
        Write-Host "  SKIP: $LocalPath (not found)" -ForegroundColor DarkYellow
        return $false
    }
    
    try {
        $ftpUri = "$ftpHost$RemotePath"
        $ftpRequest = [System.Net.FtpWebRequest]::Create($ftpUri)
        $ftpRequest.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
        $ftpRequest.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
        $ftpRequest.UseBinary = $true
        $ftpRequest.KeepAlive = $false
        $ftpRequest.Timeout = 30000
        
        $fileContent = [System.IO.File]::ReadAllBytes($LocalPath)
        $ftpRequest.ContentLength = $fileContent.Length
        
        $requestStream = $ftpRequest.GetRequestStream()
        $requestStream.Write($fileContent, 0, $fileContent.Length)
        $requestStream.Close()
        
        $response = $ftpRequest.GetResponse()
        $response.Close()
        
        $sizeKB = [math]::Round($fileContent.Length / 1024, 1)
        Write-Host "  OK: $RemotePath ($sizeKB KB)" -ForegroundColor Green
        return $true
    }
    catch {
        Write-Host "  FAIL: $RemotePath - $($_.Exception.Message)" -ForegroundColor Red
        return $false
    }
}

function Ensure-FtpDirectory {
    param ([string]$RemoteDir)
    
    try {
        $ftpUri = "$ftpHost$RemoteDir"
        $ftpRequest = [System.Net.FtpWebRequest]::Create($ftpUri)
        $ftpRequest.Credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)
        $ftpRequest.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $ftpRequest.KeepAlive = $false
        $response = $ftpRequest.GetResponse()
        $response.Close()
        Write-Host "  DIR CREATED: $RemoteDir" -ForegroundColor Cyan
    }
    catch {
        # Directory likely already exists, ignore
    }
}

# ===========================================
# Main Deploy
# ===========================================
Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  DEPLOY WEBSITE - narayanakarimunjawa.com" -ForegroundColor Cyan
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  FTP Host : $ftpHost" -ForegroundColor Gray
Write-Host "  Target   : $remoteBase" -ForegroundColor Gray
Write-Host "  Time     : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" -ForegroundColor Gray
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host ""

$successCount = 0
$failCount = 0

# --- STEP 1: Deploy Website Files ---
Write-Host "[1/2] Deploying website files to narayanakarimunjawa.com..." -ForegroundColor Yellow
Write-Host ""

# Ensure remote directories exist
$remoteDirs = @(
    "$remoteBase/includes",
    "$remoteBase/assets",
    "$remoteBase/assets/css",
    "$remoteBase/assets/js",
    "$remoteBase/uploads",
    "$remoteBase/uploads/destinations",
    "$remoteBase/uploads/logo"
)

foreach ($dir in $remoteDirs) {
    Ensure-FtpDirectory -RemoteDir $dir
}

# Upload web files
foreach ($file in $webFiles) {
    $localPath = Join-Path $localBase $file
    $remotePath = "$remoteBase/$file"
    
    $result = Upload-FtpFile -LocalPath $localPath -RemotePath $remotePath
    if ($result) { $successCount++ } else { $failCount++ }
}

Write-Host ""

# --- STEP 2: Deploy ADF System Files ---
if ($deployAdfSystem) {
    Write-Host "[2/2] Deploying developer files to adf_system..." -ForegroundColor Yellow
    Write-Host ""
    
    foreach ($file in $adfFiles) {
        $localPath = Join-Path $localAdfBase $file
        $remotePath = "$remoteAdfBase/$file"
        
        $result = Upload-FtpFile -LocalPath $localPath -RemotePath $remotePath
        if ($result) { $successCount++ } else { $failCount++ }
    }
}

# --- Summary ---
Write-Host ""
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host "  DEPLOY COMPLETE!" -ForegroundColor Green
Write-Host "  Success: $successCount files" -ForegroundColor Green
if ($failCount -gt 0) {
    Write-Host "  Failed : $failCount files" -ForegroundColor Red
}
Write-Host "========================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "  Website : https://narayanakarimunjawa.com" -ForegroundColor White
Write-Host "  Developer: https://adfsystem.online/adf_system/developer/web-settings.php" -ForegroundColor White
Write-Host ""
