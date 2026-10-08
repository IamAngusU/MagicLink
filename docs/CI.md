# CI mirror and proof

The canonical source is public in `IamAngusU/MagicLink`. GitHub Actions run in
the separate public `angusu-de/MagicLink-CI` harness so the proof surface and
its workflow history remain easy to inspect. Both accounts have the same
maintainer; this is operational evidence, not an independent audit.

The harness receives one exact 40-character source commit and checks it out from
the public canonical repository without a deploy key or repository secret. It
does not duplicate the source, a release ZIP or another source-bearing artifact.

## Gates

One push creates six proof segments:

- PHP 8.2, 8.3 and 8.4 against SQLite;
- PHP 8.4 against a real MySQL 8.4 service;
- a shared-hosting ZIP build with a sensitive-file boundary check;
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
canonical `main`, then dispatch that exact SHA. They never push source to the
public harness. Authenticate both GitHub accounts once to dispatch the workflow.

Releases live in the canonical repository. The harness can build a ZIP in an
ephemeral runner, but only its checksum may leave the job; it cannot publish a
release or upload the package.
