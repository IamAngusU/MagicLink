# Independent security review wanted

MagicLink is seeking an independent review before recommending broad production
adoption. No independent audit has been completed or is claimed. Automated
checks and maintainer review do not substitute for that work.

## Review scope

Review an immutable source commit and record the deployment environment.
Start with [the threat model](THREAT-MODEL.md), [security policy](../SECURITY.md),
the API contract and the tests. Priority paths are:

- token generation, fragment handling, expiry, concurrent consumption and replay;
- initiating-browser binding, session fixation, CSRF and exact-origin checks;
- enumeration resistance, queue saturation, timing and rate-limit bypass;
- confidential-client handoff: exact redirects, PKCE, state and one-time exchange;
- mail outbox encryption, retries, worker leases and SMTP TLS verification;
- shared-hosting document roots, proxy trust, MySQL TLS and secret exposure.

Use synthetic identities and a local mail sink. Do not probe other people's
installations or send unsolicited messages. Report vulnerabilities through
[GitHub's private reporting form](https://github.com/IamAngusU/MagicLink/security/advisories/new).
For review coordination, open an issue with your proposed scope and commit;
do not disclose an unpatched vulnerability there. There is no promised bounty.

## Acceptance evidence

A completed review should state the reviewer, commit, deployment matrix,
methods, findings and limitations. Link its report and remediation evidence
here only with the reviewer's consent. An empty finding list is not a guarantee.
