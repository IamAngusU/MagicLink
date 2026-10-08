#!/usr/bin/env sh
set -eu

mirror="${MAGICLINK_CI_MIRROR:-https://angusu-de@github.com/angusu-de/MagicLink-CI.git}"

if [ -n "$(git status --porcelain)" ]; then
  echo "The checkout must be clean so CI proves an exact commit." >&2
  exit 2
fi

php bin/check.php
head="$(git rev-parse HEAD)"
git push "$mirror" "$head:refs/heads/main"
printf 'CI mirror updated to %.12s.\n' "$head"
