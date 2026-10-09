# Démarrage automatique et idempotent des serveurs de développement.
# Appelé à l'ouverture de session Windows (clé HKCU ...\Run).

$root = Split-Path -Parent $PSScriptRoot
$backendDir = Join-Path $root 'backend'
$frontendDir = Join-Path $root 'frontend'

function Test-PortListen([int]$Port) {
    $listening = netstat -ano | Select-String -Pattern 'LISTENING'
    return $null -ne ($listening | Select-String -Pattern ":$Port\s" | Select-Object -First 1)
}

if (-not (Test-PortListen 8001)) {
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "Set-Location '$backendDir'; php artisan serve --host=127.0.0.1 --port=8001"
}

if (-not (Test-PortListen 5173)) {
    Start-Process powershell -ArgumentList '-NoExit', '-Command', "Set-Location '$frontendDir'; npm run dev"
}
