# Threat model

## Assets

- the one-time bearer secret;
- the authenticated PHP session;
- the normalized email identity;
- the application key and mail credentials;
- the allowlist and metadata-only audit history;
- handoff state, PKCE binding, one-time code and relying-party client secret.

## Defended paths

| Threat | Control |
| --- | --- |
| Access/proxy logs capture token | secret stays in URL fragment |
| Mail scanner consumes GET link | GET renders exchange only; POST consumes |
| Database leak exposes active token | purpose-separated HMAC only |
| Database leak exposes email | authenticated encryption envelope |
| Link reuse | conditional update plus transaction |
| Concurrent exchange | SQLite `BEGIN IMMEDIATE` or database transaction |
| Cross-site request | session CSRF plus Origin/Referer check |
| Email/account enumeration | uniform waiting state and decoy row |
| SMTP timing reveals allowlist | request transaction queues allowed and decoy rows without network mail |
| Request flooding | fixed-window global, IP and email HMAC counters plus a bounded queue |
| Full-queue identity oracle | every identity receives the same capacity rejection |
| Token guessing / counter growth | global, IP and selector exchange budgets before lookup/audit |
| Cross-device session theft | state polling never returns identity or authenticates; only token exchange does |
| Stored-data growth | deterministic due-checks, admission-derived batches, bounded catch-up and retention |
| Session-file growth | stateless health route plus application-owned PHP GC lifetime/cadence |
| Oversized pre-parsed forms | ingress/PHP caps match the app default; multipart is not accepted |
| Proxy-header spoofing | forwarding chain is ignored unless direct peer is explicitly trusted |
| Session fixation | ID rotation after authentication |
| Host header poisoning | links use configured `APP_URL`, never request Host |
| Database peer substitution | remote production requires mysqlnd, CA and host verification |
| Legacy SMTP downgrade | certificate/host verification and TLS 1.2+ protocol floor |
| Handoff login/CSRF mix-up | exact redirect, RP-session state, browser binding, S256 PKCE and one-time code |

## Explicit non-goals

- Protecting an already compromised mailbox or browser.
- Replacing authorization in the host application.
- Hiding traffic timing or the fact that mail was sent from the mail provider.
- Defending a server where an attacker can read `.env`, `storage/app.key` and the
  database together.
- Providing phishing-resistant authentication comparable to a correctly
  deployed passkey. Magic links inherit mailbox security.
- Absorbing volumetric DDoS before PHP; use upstream controls for that layer.
