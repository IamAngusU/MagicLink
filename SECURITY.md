# Security policy

## Supported deployment

- PHP 8.2 or newer with PDO and either Sodium or OpenSSL.
- HTTPS for every production request.
- `public/` as the document root whenever the host supports it.
- A configured allowlist, unless open registration is an explicit decision.
- A working mail transport with SPF, DKIM and DMARC configured for the sender.

Run `php bin/doctor.php` after every deployment or PHP upgrade.

## Built-in controls

- 256-bit bearer tokens generated with `random_bytes`.
- Purpose-separated HMACs for token, email, IP, session and rate-limit keys.
- Token secret in the URL fragment; GET never consumes a link.
- Same-origin, CSRF-protected POST exchange.
- Atomic one-time consumption with replay auditing.
- Short TTL and invalidation of older links for the same identity.
- Per-IP and per-email rate limits.
- Encrypted email storage using Sodium secretbox or AES-256-GCM.
- Session ID rotation, `HttpOnly`, `SameSite=Lax` and production `Secure` cookies.
- CSP, no-referrer, no-store, frame denial and MIME-sniffing protection.
- Enumeration-safe waiting state for addresses outside the allowlist.
- Metadata-only audit rows; raw IP addresses and email addresses are not logged.

## Operator responsibilities

MagicLink does not terminate TLS, patch PHP, authorize application actions or
secure the mailbox receiving a link. A compromised mailbox is a compromised
MagicLink identity. Keep `storage/`, `.env` and the database outside the public
document root or behind the supplied deny rules. Back up `storage/app.key`
together with the database; losing one makes stored email envelopes unreadable.

Do not enable `MAGICLINK_ALLOW_ANY_EMAIL=true` unless the product is deliberately
open to every email address. Do not use `MAIL_TRANSPORT=log` outside local
development.

## Reporting

Use the private repository's GitHub Security Advisory flow. Include the affected
commit, deployment mode, reproduction steps and whether a real token or email
address was exposed. Do not place live tokens, SMTP credentials or `.env`
contents in an issue.
