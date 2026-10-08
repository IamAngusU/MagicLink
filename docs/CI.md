# CI mirror and proof

The canonical source stays private in `IamAngusU/MagicLink`. GitHub Actions run
in the public, source-free `angusu-de/MagicLink-CI` harness, because public
hosted runners do not depend on private-repository billing. Both accounts have
the same maintainer; this is operational evidence, not an independent audit.

The harness receives one exact 40-character source commit and checks it out with
a read-only deploy key scoped only to the private MagicLink repository. It never
stores the source, the key, a release ZIP or another source-bearing artifact.

## Gates

One push creates six proof segments:

- PHP 8.2, 8.3 and 8.4 against SQLite;
- PHP 8.4 against a real MySQL 8.4 service;
- a shared-hosting ZIP build with a private-file boundary check;
- a pinned GitHub Actions security audit.

The test workflow has no repository write permission. A separate, guarded
`workflow_run` job reads completed job conclusions and force-replaces only the
orphan `ci-proof` branch with `proof/ci-proof.svg` and machine-readable JSON.
The visual template comes from `IamAngusU/Badges`.

## Request a run

From a clean canonical checkout:

```powershell
.\scripts\run-ci.ps1
```

```sh
./scripts/run-ci.sh
```

The scripts run local checks, require a clean commit already reachable from the
private canonical `main`, then dispatch that exact SHA. They never push source to
the public harness. Authenticate both GitHub accounts once; the deploy key is a
one-time repository setup and remains encrypted as a harness secret.

Releases remain in the canonical private repository. The harness can build a ZIP
in an ephemeral runner, but only its checksum may leave the job; it cannot
publish a release or upload the package.
