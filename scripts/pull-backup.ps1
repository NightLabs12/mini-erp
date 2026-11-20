# Tarik berkas backup dari VPS ke laptop.
#
# Pemakaian:
#   .\scripts\pull-backup.ps1 -Server 172.232.245.132 -User dev
#
# Hanya berkas yang belum ada di tujuan yang disalin. Ukuran sisi remote
# diambil sekali untuk semua berkas, jadi jumlah prompt password sama dengan
# jumlah berkas yang disalin (nol bila SSH key sudah dipasang).

[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $Server,
    [string] $User = 'dev',
    [string] $RemotePath = 'backups/',
    [string] $Destination = 'D:\Backup\mini-erp'
)

$ErrorActionPreference = 'Stop'

# `-Server` dipakai, bukan `-Host`: $Host adalah automatic variable PowerShell
# yang read-only, sehingga parameter bernama Host selalu gagal.
foreach ($cmd in 'ssh', 'scp') {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        throw "$cmd tidak ditemukan. Pasang OpenSSH Client lewat Settings > Apps > Optional Features."
    }
}

$target = "$User@$Server"
New-Item -ItemType Directory -Force -Path $Destination | Out-Null

# Satu panggilan ssh mengembalikan ukuran dan path sekaligus. Formatnya
# "<byte> <path>", dipisah spasi pertama karena path tidak memuat spasi.
$listing = ssh $target "stat -c '%s %n' ~/$RemotePath*.dump ~/$RemotePath*.tar.gz 2>/dev/null"
if (-not $listing) {
    throw "Tidak ada berkas backup di ${target}:~/$RemotePath"
}

$transferred = 0
foreach ($line in $listing) {
    $parts = $line -split ' ', 2
    if ($parts.Count -ne 2) { continue }
    $remoteSize = [int64]$parts[0]
    $remote = $parts[1]
    $name = Split-Path -Leaf $remote
    $local = Join-Path $Destination $name

    if (Test-Path $local) {
        Write-Host "Lewati $name (sudah ada)" -ForegroundColor DarkGray
        continue
    }

    Write-Host "Menyalin $name" -ForegroundColor Cyan
    scp "${target}:$remote" $local
    if ($LASTEXITCODE -ne 0) { throw "scp gagal untuk $name" }

    $localSize = (Get-Item $local).Length
    if ($localSize -ne $remoteSize) {
        Remove-Item $local -Force
        throw "Ukuran tidak cocok untuk $name (lokal $localSize, remote $remoteSize). Berkas dihapus."
    }

    $hash = (Get-FileHash $local -Algorithm SHA256).Hash
    Write-Host ("  {0:N0} byte  SHA256 {1}" -f $localSize, $hash) -ForegroundColor DarkGray
    $transferred++
}

Write-Host "Selesai. $transferred berkas baru di $Destination"
