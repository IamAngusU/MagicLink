param(
    [string]$Mirror = "https://angusu-de@github.com/angusu-de/MagicLink-CI.git"
)

$ErrorActionPreference = "Stop"

if ((git status --porcelain).Length -ne 0) {
    throw "The checkout must be clean so CI proves an exact commit."
}

php bin/check.php
if ($LASTEXITCODE -ne 0) {
    throw "Local MagicLink checks failed."
}

$head = (git rev-parse HEAD).Trim()
git push $Mirror "${head}:refs/heads/main"
if ($LASTEXITCODE -ne 0) {
    throw "Push to the CI mirror failed. Authenticate angusu-de and retry."
}

Write-Host "CI mirror updated to $($head.Substring(0, 12))."

