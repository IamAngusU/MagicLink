# Threat model

## Assets

- the one-time bearer secret;
- the authenticated PHP session;
- the normalized email identity;
- the application key and mail credentials;
- the allowlist and metadata-only audit history.

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
| Request flooding | IP and email HMAC buckets |
| Session fixation | ID rotation after authentication |
| Host header poisoning | links use configured `APP_URL`, never request Host |

## Explicit non-goals

- Protecting an already compromised mailbox or browser.
- Replacing authorization in the host application.
- Hiding traffic timing or the fact that mail was sent from the mail provider.
- Defending a server where an attacker can read `.env`, `storage/app.key` and the
  database together.
- Providing phishing-resistant authentication comparable to a correctly
  deployed passkey. Magic links inherit mailbox security.
