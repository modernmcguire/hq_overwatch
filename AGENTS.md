# MMP Overwatch — Agent Guide

> Directives for AI coding agents working **inside a project that installs this
> package**, or maintaining the package itself. Humans: see `README.md`.

## What this package is

`modernmcguire/hq_overwatch` is the **MMP HQ companion package**. Its first capability
is **trusted single sign-on**: MMP HQ (the internal CRM at `modernmcguire` HQ) can sign
a short-lived token that logs an MMP staff member straight into this application without
a password.

## Trust model (do not weaken)

- **Asymmetric, per-project.** HQ holds an Ed25519 **private** key for this project and
  signs tokens with it. This app holds only the matching **public** key. This app can
  verify HQ's signature but can never mint a token — there is no shared secret here.
- The public key lives in `MMP_OVERWATCH_PUBLIC_KEY` (read by `config/mmp.php`). It is
  issued by HQ's "Link" wizard. A public key is not secret, but rotating it in HQ
  invalidates the old one.

A token is accepted only if **all** of these hold (`src/Support/TokenVerifier.php`):
1. Wire size is within `MAX_TOKEN_BYTES` (cheap guard before any crypto).
2. Ed25519 signature validates against the configured public key.
3. `iss` is `mmp-hq`.
4. Lifetime is valid: `iat` is not future-dated, `exp` is not past, AND the token is
   no older than `token_ttl` (the project enforces its own max age via `iat` rather
   than trusting HQ's `exp`; ~60s with a small clock-skew allowance).
5. `purpose` matches the endpoint (`login` for `/sso`, `status` for `/status`).
6. `aud` equals the exact request host — this is also how environment binding works
   (a token minted for the dev host cannot be replayed at the prod host).
7. (login only) `sub` email is on `allowed_domain` (`modernmcguire.com`).
8. `jti` has not been seen before (single-use; replay protection via cache).

**When editing this package, never relax any of these checks, never widen the domain,
never lengthen the TTL meaningfully, and never log token contents.** Failures abort
with a generic 403 on purpose — do not add detail to error responses.

### Operational requirements (the verifier assumes these)
- **A shared, persistent cache** (`redis` or `database`) — replay protection stores the
  `jti` there. With the `array`/`null` store, or a per-node cache behind a load
  balancer, a token could be replayed within its TTL window. The verifier logs a
  warning when it detects `array`/`null`.
- **Correct proxy/host config** (`TrustProxies` / `TrustHosts`) — `aud` is compared to
  `request()->getHost()`. Per-environment keys already stop a token working on the wrong
  host, but a trustworthy host value is the first line.

## Endpoints (mounted under `config('mmp.overwatch.route_prefix')`, default `mmp/overwatch`)

| Method & path            | Purpose                                                        |
|--------------------------|----------------------------------------------------------------|
| `GET /mmp/overwatch/status` | Signed capability + connection check. Requires a `purpose:status` token; returns `{overwatch, version}` on success, `403` otherwise. Discloses nothing to unauthenticated callers — a valid signature already proves reachability AND a correct key. No login. |
| `GET /mmp/overwatch/sso`    | Real login. Validates a `purpose:login` token, provisions + logs in the user. |

## Configuring this package in a consuming project

1. `composer require modernmcguire/hq_overwatch`
2. (optional) `php artisan vendor:publish --tag=mmp-config` to get `config/mmp.php`.
3. In HQ, open the project → **Link Overwatch** → copy the public key into this app's
   `.env`:
   ```
   MMP_OVERWATCH_PUBLIC_KEY=base64-public-key-from-hq
   ```
4. Run HQ's wizard live test. Green = ready.

### Customising user provisioning

Default behaviour when a token logs in an unknown email
(`src/Support/UserProvisioner.php`):
1. If `spatie/laravel-permission` is installed → assign an `admin` role (create if missing).
2. Else if the `users` table has `is_admin` → set it `true`.
3. Else if it has a `role` column → set it `admin`.
4. Else → create a plain user.

To override, set a closure in `config/mmp.php`:
```php
'provision_user' => function (array $claims) {
    return \App\Models\User::firstOrCreate(
        ['email' => $claims['sub']],
        ['name' => $claims['name'], 'team_id' => 1],
    )->assignRole('owner');
},
```
The closure receives the validated claims and must return an `Authenticatable`.

## Token contract (shared with HQ — keep both sides in sync)

Wire format: `base64url(payloadJson) . "." . base64url(ed25519Signature)`

Claims:
```
iss      "mmp-hq"
aud      target host (e.g. "app.example.com")
sub      user email            (login only)
name     user display name     (login only)
avatar   avatar url (optional) (login only)
env      "production" | ...     (audit/log only; binding is via aud)
purpose  "login" | "status"
jti      unique id (single-use)
iat      issued-at (unix)
exp      expiry (unix)
```

If you change this contract, you **must** change HQ's `OverwatchService` in the HQ repo
identically, or every login breaks. See `MAINTENANCE.md`.

## Tests

`composer install && ./vendor/bin/pest`. Tests run on `orchestra/testbench` with an
in-memory SQLite DB and a fresh Ed25519 keypair per run (`tests/TestCase.php` mints
tokens exactly as HQ does). Cover: valid login, expired, wrong host, wrong domain,
tampered, replayed, purpose cross-use, and each provisioning branch. Add a test for any
behaviour change.
