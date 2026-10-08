# CI mirror and proof

The canonical private source is `IamAngusU/MagicLink`. GitHub Actions run in the
private `angusu-de/MagicLink-CI` mirror so its billing and runner quota are used.
Both accounts have the same maintainer; this is operational separation, not an
independent security audit.

## Gates

One push creates six proof segments:

- PHP 8.2, 8.3 and 8.4 against SQLite;
- PHP 8.4 against a real MySQL 8.4 service;
- a shared-hosting ZIP build with a private-file boundary check;
- a pinned GitHub Actions security audit.

The CI workflow has no repository write permission. A separate, guarded
`workflow_run` job reads completed job conclusions and force-replaces only the
orphan `ci-proof` branch with `proof/ci-proof.svg` and machine-readable JSON.
The visual template comes from `IamAngusU/Badges`.

## Publish to the mirror

From a clean canonical checkout:

```powershell
.\scripts\push-ci-mirror.ps1
```

```sh
./scripts/push-ci-mirror.sh
```

The scripts run the local checks first and push the exact committed `HEAD` to
the mirror's `main` branch. They do not copy local `.env`, storage, uncommitted
work, or tags. Authenticate the `angusu-de` GitHub account once before the first
push.

Releases remain in the canonical repository. The mirror's manual release-proof
workflow only builds a short-lived artifact and cannot publish a release.

