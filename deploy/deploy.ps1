# deploy.ps1 — deploy Choronai from a WINDOWS computer.
#
# It does NOT build anything on Windows. It connects to the Linux server over
# SSH and runs deploy.sh there (where Docker lives). Windows 10/11 already have
# `ssh` built in (PowerShell), so nothing extra to install.
#
#   Usage (PowerShell):
#     ./deploy.ps1 -Server 1.2.3.4 -User ubuntu -Key C:\path\to\key.pem
#
#   Or set defaults below and just run:  ./deploy.ps1
#
param(
  [string]$Server = "YOUR.SERVER.IP",      # e.g. 1.2.3.4 or pos.example.com
  [string]$User   = "ubuntu",              # VPS login user (ubuntu / root / ec2-user ...)
  [string]$Key    = "",                    # path to your private key (.pem); blank = default SSH key
  [string]$BackDir = "~/Choronai-backend"  # backend folder on the server
)

$ErrorActionPreference = "Stop"

if ($Server -eq "YOUR.SERVER.IP") {
  Write-Host "Set your server first:  ./deploy.ps1 -Server <ip> -User <user> -Key <key.pem>" -ForegroundColor Yellow
  exit 1
}

# Build the ssh command (add -i only when a key file is given)
$sshArgs = @()
if ($Key -ne "") { $sshArgs += @("-i", $Key) }
$sshArgs += "$User@$Server"
$sshArgs += "bash $BackDir/deploy/deploy.sh"

Write-Host "==> Deploying to $User@$Server ..." -ForegroundColor Cyan
ssh @sshArgs

if ($LASTEXITCODE -eq 0) {
  Write-Host "`n==> Deploy finished successfully." -ForegroundColor Green
} else {
  Write-Host "`n==> Deploy FAILED (exit $LASTEXITCODE). See the output above." -ForegroundColor Red
  exit $LASTEXITCODE
}
