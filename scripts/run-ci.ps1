$ErrorActionPreference = "Stop"

if ((git status --porcelain).Length -ne 0) {
    throw "The checkout must be clean so CI proves an exact commit."
}

php bin/check.php
if ($LASTEXITCODE -ne 0) {
    throw "Local MagicLink checks failed."
}

$head = (git rev-parse HEAD).Trim()
git fetch --quiet origin main
git merge-base --is-ancestor $head origin/main
if ($LASTEXITCODE -ne 0) {
    throw "Push this commit to IamAngusU/MagicLink main before requesting public CI."
}

$previousToken = [Environment]::GetEnvironmentVariable("GH_TOKEN", "Process")
try {
    $token = (gh auth token --hostname github.com --user angusu-de).Trim()
    if ($token -eq "") {
        throw "The angusu-de GitHub account is not authenticated."
    }
    [Environment]::SetEnvironmentVariable("GH_TOKEN", $token, "Process")
    gh workflow run ci.yml --repo angusu-de/MagicLink-CI --ref main -f "source_sha=$head"
    if ($LASTEXITCODE -ne 0) {
        throw "Could not dispatch the public CI harness."
    }
} finally {
    [Environment]::SetEnvironmentVariable("GH_TOKEN", $previousToken, "Process")
}

Write-Host "CI requested for source $($head.Substring(0, 12))."
