# Changelog

## 0.4.1 — 2026-10-08

- Licensed MagicLink under MIT and prepared the canonical repository and
  release package for public use.
- Rebuilt the concise bilingual front door around a direct quick start,
  factual repository badges, terminal and protocol visuals, and clear product
  boundaries.
- Removed the public CI mirror's private-source and deploy-key dependency while
  preserving immutable-commit checks and machine-readable proof.

## 0.4.0 — 2026-10-08

- Added safe, local DE/EN mail-template packs, strict placeholder rendering and
  a CLI preview without PHP evaluation, uploads or remote template loading.
- Added an optional RP-initiated authorization-code handoff with browser-bound
  state, exact redirect matching, PKCE S256, one-time codes and deterministic
  recovery after a successful Magic-Link exchange.
- Hardened queued mail with stable Message-IDs, SMTP connection reuse, lease
  heartbeats, ownership checks, jittered retries and permanent/transient errors.
- Added global admission and queue-capacity budgets, bounded queue scans,
  scale indexes, schema migration locking and operational status output.
- Added fail-closed session storage, a configured shared-handler option for
  multi-node deployments and verified TLS modes for remote production MySQL.
- Made full-queue responses identity-independent, restricted remote MySQL to
  verified `mysqlnd`, enforced SMTP TLS 1.2+, added pre-parser body caps and an
  application-owned file-session GC policy.
- Replaced sampled cleanup with a 0.005 ms deterministic due-check and an
  admission-derived maintenance batch with 25% capacity headroom.
- Replaced generic language-switch effects with accessible flag-colour foils
  and expanded bilingual integration, configuration and operations docs.

## 0.3.1 — 2026-10-08

- Added concise English/German onboarding, measured SQLite performance notes,
  flow and terminal visuals, language switches and clickable stack provenance.

## 0.3.0 — 2026-10-08

- Added guided installation, PHP-version-safe eager SQLite writes and a pinned,
  public source-free CI proof harness for the private canonical repository.

## 0.2.0 — 2026-10-08

- Added a versioned headless API with stable response envelopes, session status,
  owned state polling and automatically bounded batch reads.
- Changed cross-device semantics so only the browser presenting the secret is
  authenticated; the requester can observe confirmation without receiving an
  identity or session.
- Moved delivery behind an encrypted outbox with after-response zero-setup mode,
  dedicated worker, retries, stale-claim recovery and enumeration-safe timing.
- Replaced row-per-hit request limiting with fixed-window counters and added
  exchange IP/selector budgets, body limits, proxy validation and session expiry.
- Added bounded retention maintenance, driver-aware batch defaults, operations
  tooling and focused abuse/security regression tests.
- Split short onboarding from detailed API, configuration and high-load docs.

## 0.1.0 — 2026-10-07

- Extracted the production-tested PRISM selector/fragment exchange into a
  standalone PHP application.
- Added atomic one-time verification, replay auditing, cross-device state sync,
  encrypted identity storage and enumeration-safe allowlists.
- Added SQLite and MySQL storage, PHP mail and verified-TLS SMTP transports.
- Added Apache shared-hosting and Nginx/Apache VPS deployment paths.
- Added German and English states, a private Badges catalog entry and an Icon
  Marquee stack asset.
