# Changelog

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
