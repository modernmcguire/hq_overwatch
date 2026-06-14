# MMP Overwatch

The **MMP HQ companion package** for Modern McGuire Laravel projects.

## Requirements

- PHP 8.2+
- Laravel 10 – 13

## Install

```bash
composer require modernmcguire/hq_overwatch
```

Publish the config if you want to edit it:

```bash
php artisan vendor:publish --tag=mmp-config
```

## Configure

In **MMP HQ**, open the project's panel → **Link Overwatch** → copy the issued public
key into this app's `.env`:

```env
MMP_OVERWATCH_PUBLIC_KEY=base64-public-key-from-hq
```

Then run HQ's **live test** in the link wizard. Green means the key is installed and SSO
will work.

### `config/mmp.php`

| Key | Default | Purpose |
|-----|---------|---------|
| `overwatch.public_key` | `env('MMP_OVERWATCH_PUBLIC_KEY')` | HQ's Ed25519 public key for this project. |
| `overwatch.allowed_domain` | `modernmcguire.com` | Only emails on this domain may sign in. |
| `overwatch.redirect_to` | `/` | Where users land after login. |
| `overwatch.token_ttl` | `60` | Max accepted token age (seconds). |
| `overwatch.route_prefix` | `mmp/overwatch` | URI prefix for the package routes. |
| `overwatch.provision_user` | `null` | Optional `fn (array $claims): Authenticatable` override. |

## How a user gets provisioned

When an unrecognised email signs in, the package creates the user and elevates them to
admin using whatever the app supports: a Spatie `admin` role, an `is_admin`/`role`
column, or a plain user if neither exists. Override entirely with the
`provision_user` closure (see `AGENTS.md`).

## Security

Asymmetric, per-environment keys (HQ signs, this app verifies); tokens are single-use,
host-bound, domain-restricted, issuer-checked, size-capped, and expire in ~60s (the app
enforces its own max age from `iat`, not just HQ's `exp`). Full details in `AGENTS.md`
and `MAINTENANCE.md`.

**Requirements for the guarantees to hold:**

- Use a **shared, persistent cache** (`redis` or `database`) — the single-use `jti` is
  stored there. The `array`/`null` store (or a per-node cache behind a load balancer)
  weakens replay protection; the package logs a warning if it sees one.
- Run behind correct **`TrustProxies` / `TrustHosts`** config so `request()->getHost()`
  (used for `aud` binding) can't be spoofed.

## Tests

```bash
composer install
./vendor/bin/pest
```
