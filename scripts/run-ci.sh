#!/usr/bin/env sh
set -eu

if [ -n "$(git status --porcelain)" ]; then
  echo "The checkout must be clean so CI proves an exact commit." >&2
  exit 2
fi

php bin/check.php
head="$(git rev-parse HEAD)"
git fetch --quiet origin main
git merge-base --is-ancestor "$head" origin/main || {
  echo "Push this commit to IamAngusU/MagicLink main before requesting public CI." >&2
  exit 2
}

token="$(gh auth token --hostname github.com --user angusu-de)"
GH_TOKEN="$token" gh workflow run ci.yml \
  --repo angusu-de/MagicLink-CI \
  --ref main \
  -f "source_sha=$head"
unset token
printf 'CI requested for source %.12s.\n' "$head"
