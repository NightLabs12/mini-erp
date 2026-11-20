# Tarik backup sekali per hari, dipicu saat laptop hidup atau login.
#
# Pemakaian (dipanggil Task Scheduler, bukan manual):
#   powershell -NoProfile -ExecutionPolicy Bypass -File pull-backup-daily.ps1
#
# Berkas penanda menyimpan tanggal tarikan terakhir, sehingga task yang dipicu
# berkali-kali dalam sehari (login, unlock, jadwal 10:00) hanya benar-benar
# menyalin sekali. Log di %LOCALAPPDATA%\mini-erp-backup\pull.log.

[CmdletBinding()]
param(
    [string] $Server = '172.232.245.132',
    [string] $User = 'dev',
    [string] $Destination = 'D:\Backup\mini-erp',
    [int] $RetryDelaySeconds = 30
)

$ErrorActionPreference = 'Stop'

$stateDir = Join-Path $env:LOCALAPPDATA 'mini-erp-backup'
$marker = Join-Path $stateDir 'last-pull.txt'
$logFile = Join-Path $stateDir 'pull.log'
New-Item -ItemType Directory -Force -Path $stateDir | Out-Null

function Write-Log([string] $Message) {
    $line = '[{0}] {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Add-Content -Path $logFile -Value $line -Encoding UTF8
}

$today = (Get-Date).ToString('yyyy-MM-dd')
if ((Test-Path $marker) -and ((Get-Content $marker -Raw).Trim() -eq $today)) {
    Write-Log "Lewati: sudah menarik backup hari ini."
    exit 0
}

# Agent harus jalan, kalau tidak ssh akan menggantung menunggu passphrase.
$agent = Get-Service ssh-agent -ErrorAction SilentlyContinue
if (-not $agent -or $agent.Status -ne 'Running') {
    Write-Log 'GAGAL: service ssh-agent tidak berjalan. Jalankan: Start-Service ssh-agent (butuh admin).'
    exit 1
}

# Login bisa terjadi sebelum jaringan siap, jadi percobaan pertama yang gagal
# karena koneksi diulang sekali sebelum dianggap gagal.
for ($attempt = 1; $attempt -le 2; $attempt++) {
    $probe = ssh -o BatchMode=yes -o ConnectTimeout=10 "$User@$Server" 'echo ok' 2>&1
    if ($probe -eq 'ok') { break }
    Write-Log "Percobaan $attempt gagal menghubungi $Server : $probe"
    if ($attempt -eq 2) { exit 1 }
    Start-Sleep -Seconds $RetryDelaySeconds
}

$script = Join-Path $PSScriptRoot 'pull-backup.ps1'
# *>&1 ikut menangkap Write-Host, yang di PowerShell 5.1 masuk stream information.
$output = & $script -Server $Server -User $User -Destination $Destination *>&1
$code = $LASTEXITCODE
$output | ForEach-Object { Write-Log $_ }

if ($code -ne 0) {
    Write-Log "GAGAL: pull-backup.ps1 keluar dengan kode $code."
    exit $code
}

Set-Content -Path $marker -Value $today -Encoding ASCII
Write-Log 'Selesai.'
exit 0
