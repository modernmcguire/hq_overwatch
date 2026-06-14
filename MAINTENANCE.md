# Maintenance & Architecture Notes

Durable context for whoever (human or agent) maintains this package later. Written at
initial build, June 2026.

## Why this exists

MMP runs ~30 Laravel apps. The team needed one-click "sign in to this project" from the
MMP HQ CRM project panel, without standing up OAuth/SAML per app or sharing passwords.
Overwatch is the project-side half of that: a thin, install-and-forget package that
trusts HQ to assert "this @modernmcguire.com person should be logged in here, now".

## The two halves (keep them in lockstep)

| | Repo | Role |
|---|---|---|
| **Issuer** | `crm` (MMP HQ) — `app/Services/Projects/OverwatchService.php` | Holds each project's **private** key, mints + signs tokens, pings/links projects. |
| **Verifier** | this package, installed in each project | Holds only its own **public** key, verifies tokens, provisions + logs in. |

The **token contract** (claims + wire format, see `AGENTS.md`) is the shared interface.
Changing it on one side without the other breaks every login. If you must change it,
version it (e.g. add a `v` claim) and have the verifier accept the old + new during a
rollout.

## Key design decisions (and why)

- **Asymmetric, per-project Ed25519** rather than a single shared HMAC secret. HQ never
  holds anything that can impersonate a project (it signs; projects only verify), and a
  leaked key compromises exactly one project. Per-project (not one global HQ key) so the
  "Link / Rotate" UX maps to a single project's key.
- **Ed25519 via libsodium**, not a JWT library. libsodium ships in PHP core (7.2+), so
  there is zero crypto dependency to keep patched. The token is a deliberately tiny
  `base64url(json).base64url(sig)` envelope, not a full JWT — we don't need JOSE.
- **Host (`aud`) binding == environment binding.** Dev and prod are always different
  hosts, so binding the token to the exact request host is what stops a dev-minted token
  working on prod. We keep an `env` claim too, but only for auditing — we deliberately do
  **not** compare it to `app()->environment()` because staging/local naming varies per
  app and would cause false rejections. If you ever add a stricter env check, make it
  opt-in per project.
- **Single-use via `Cache::add(jti)`.** Relies on the app having a real cache store
  (database/redis/file). With the `array` driver (default in tests only) replay
  protection is per-request — fine for tests, not for prod. The verifier logs a warning
  when it detects an `array`/`null` store. The jti is cached for `token_ttl + skew`
  (not the HQ-supplied `exp`, which we deliberately don't trust for sizing).
- **Local lifetime enforcement.** The verifier does not blindly trust HQ's `exp`: it
  requires `iat`, rejects future-dated tokens, and rejects anything older than the
  project's own `token_ttl`. This bounds lifetime and the jti cache TTL even if HQ ever
  mints a long-lived token. `exp` is still honoured as a not-after.
- **`aud` binding trusts `request()->getHost()`.** Deployments must set `TrustProxies`/
  `TrustHosts` correctly. Per-environment keys are the stronger backstop (a token for one
  host is signed with that host's key and won't verify elsewhere).
- **Size cap.** Tokens over `MAX_TOKEN_BYTES` (4 KB) are rejected before any base64/crypto.
- **Generic 403 on every failure.** No error detail is returned or logged, so a prober
  can't distinguish "bad signature" from "expired" from "wrong host".
- **Signed `status` (no public capability endpoint).** `status` requires a `purpose:status`
  signature, so it discloses nothing to anonymous callers. This made a separate `verify`
  endpoint redundant — a successful signed `status` already proves reachability *and* a
  correct key. HQ infers state from the HTTP code: `200` = operational, `403` = reachable
  but wrong/missing key, connection failure = unreachable.

## Files

```
src/OverwatchServiceProvider.php   registers routes + publishes config
src/Overwatch.php                  VERSION constant (bump on release; reported by /status)
config/mmp.php                     consuming-project config
routes/overwatch.php               status / sso routes
src/Http/Controllers/             StatusController (signed), SsoController
src/Support/TokenVerifier.php      ★ the security core
src/Support/UserProvisioner.php    find/create/elevate user (+ closure override)
src/Exceptions/InvalidTokenException.php
tests/                            testbench + Pest; tests/TestCase.php mints tokens like HQ
```

## Releasing

1. Bump `Overwatch::VERSION` (shown by `/status`, so HQ can see which build a project runs).
2. `./vendor/bin/pest` must be green.
3. Tag + push; private Packagist picks it up. Projects `composer update modernmcguire/hq_overwatch`.

## Likely future work

- Additional HQ↔project capabilities beyond SSO (the reason for the generic name).
- Optional per-project email allowlist (currently domain-only) — add a config key +
  check in `TokenVerifier::assertAllowedDomain`.
- Optional master enable flag if a project wants to install but keep SSO off.
- Audit-logging hook on successful login (fire an event the host app can listen to).
