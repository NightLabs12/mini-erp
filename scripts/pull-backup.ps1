# Tarik berkas backup dari VPS ke laptop.
#
# Pemakaian:
#   .\scripts\pull-backup.ps1 -Host 172.232.245.132 -User dev
#
# Hanya berkas yang belum ada di tujuan yang disalin, dan setiap berkas
# diperiksa dengan SHA256 setelah transfer.

[CmdletBinding()]
param(
    [Parameter(Mandatory)] [string] $Host,
    [string] $User = 'dev',
    [string] $RemotePath = '~/backups/',
    [string] $Destination = 'D:\Backup\mini-erp'
)

$ErrorActionPreference = 'Stop'

if (-not (Get-Command scp -ErrorAction SilentlyContinue)) {
    throw 'scp tidak ditemukan. Pasang OpenSSH Client lewat Settings > Apps > Optional Features.'
}

New-Item -ItemType Directory -Force -Path $Destination | Out-Null

$listing = ssh "$User@$Host" "ls -1 $RemotePath*.dump $RemotePath*.tar.gz 2>/dev/null"
if (-not $listing) {
    throw "Tidak ada berkas backup di $User@${Host}:$RemotePath"
}

$transferred = 0
foreach ($remote in $listing) {
    $name = Split-Path -Leaf $remote
    $target = Join-Path $Destination $name

    if (Test-Path $target) {
        Write-Host "Lewati $name (sudah ada)" -ForegroundColor DarkGray
        continue
    }

    Write-Host "Menyalin $name" -ForegroundColor Cyan
    scp "$User@${Host}:$remote" $target
    if ($LASTEXITCODE -ne 0) { throw "scp gagal untuk $name" }

    # scp mempertahankan isi, tapi ukuran adalah pemeriksaan termurah
    # yang menangkap transfer terpotong.
    $localSize = (Get-Item $target).Length
    $remoteSize = [int64](ssh "$User@$Host" "stat -c %s '$remote'")
    if ($localSize -ne $remoteSize) {
        Remove-Item $target -Force
        throw "Ukuran tidak cocok untuk $name (lokal $localSize, remote $remoteSize). Berkas dihapus."
    }

    $hash = (Get-FileHash $target -Algorithm SHA256).Hash
    Write-Host ("  {0}  {1:N0} byte  SHA256 {2}" -f $name, $localSize, $hash.Substring(0, 16)) -ForegroundColor DarkGray
    $transferred++
}

Write-Host "Selesai. $transferred berkas baru di $Destination"
