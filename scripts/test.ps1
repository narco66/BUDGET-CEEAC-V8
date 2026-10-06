$root = Split-Path -Parent $PSScriptRoot
Set-Location "$root\backend"
php artisan test --compact
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
Set-Location "$root\frontend"
npx tsc --noEmit
