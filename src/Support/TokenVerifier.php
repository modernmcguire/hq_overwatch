<?php

namespace ModernMcguire\Overwatch\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use ModernMcguire\Overwatch\Exceptions\InvalidTokenException;

/**
 * Verifies HQ-signed Overwatch tokens.
 *
 * Token wire format: base64url(payloadJson) . "." . base64url(ed25519Signature)
 *
 * @phpstan-type Claims array{
 *     iss:string, aud:string, sub?:string, name?:string, avatar?:string,
 *     env?:string, purpose:string, jti:string, iat:int, exp:int
 * }
 */
class TokenVerifier
{
    /**
     * A few seconds of leeway for clock drift between HQ and this server.
     */
    protected const CLOCK_SKEW = 5;

    /**
     * Hard upper bound on a token's wire size, enforced before any decoding or
     * cryptography so an oversized value can't drive needless work.
     */
    protected const MAX_TOKEN_BYTES = 4096;

    /**
     * Verify a token for the given purpose ("login" or "verify") and the host
     * the request actually arrived on. Returns the validated claims or throws.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidTokenException
     */
    public function verify(string $token, string $purpose, string $host): array
    {
        $claims = $this->decodeAndCheckSignature($token);

        $this->assertIssuer($claims);
        $this->assertWithinLifetime($claims);
        $this->assertPurpose($claims, $purpose);
        $this->assertAudience($claims, $host);

        if ($purpose === 'login') {
            $this->assertAllowedDomain($claims);
        }

        $this->assertSingleUse($claims);

        return $claims;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidTokenException
     */
    protected function decodeAndCheckSignature(string $token): array
    {
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new InvalidTokenException('Token too large.');
        }

        $publicKey = $this->publicKey();

        if (! str_contains($token, '.')) {
            throw new InvalidTokenException('Malformed token.');
        }

        [$encodedPayload, $encodedSignature] = explode('.', $token, 2);

        $payload = $this->base64UrlDecode($encodedPayload);
        $signature = $this->base64UrlDecode($encodedSignature);

        if ($payload === false || $signature === false) {
            throw new InvalidTokenException('Malformed token encoding.');
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new InvalidTokenException('Invalid signature length.');
        }

        if (! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new InvalidTokenException('Signature verification failed.');
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            throw new InvalidTokenException('Invalid claims payload.');
        }

        return $claims;
    }

    /**
     * Redundant with the signature (only HQ can mint), but cheap defense in
     * depth and a clear contract: tokens must come from this issuer.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertIssuer(array $claims): void
    {
        if (($claims['iss'] ?? null) !== 'mmp-hq') {
            throw new InvalidTokenException('Unexpected issuer.');
        }
    }

    /**
     * Validate the token's lifetime independently of HQ. Beyond honouring `exp`,
     * the project enforces its own maximum age via `token_ttl` (using `iat`), so
     * an over-long or future-dated token is rejected even though it is validly
     * signed. This also bounds the single-use cache entry's TTL.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertWithinLifetime(array $claims): void
    {
        $now = time();
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        $maxAge = max(1, (int) config('mmp.overwatch.token_ttl', 60));

        if (! is_int($iat) || $iat > $now + self::CLOCK_SKEW) {
            throw new InvalidTokenException('Invalid issued-at.');
        }

        if (! is_int($exp) || $exp + self::CLOCK_SKEW < $now) {
            throw new InvalidTokenException('Token expired.');
        }

        if ($now - $iat > $maxAge + self::CLOCK_SKEW) {
            throw new InvalidTokenException('Token exceeds maximum age.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertPurpose(array $claims, string $purpose): void
    {
        if (($claims['purpose'] ?? null) !== $purpose) {
            throw new InvalidTokenException('Token purpose mismatch.');
        }
    }

    /**
     * The token is bound to the exact host HQ minted it for. Because dev and
     * prod are always different hosts, this is also what enforces environment
     * binding — a token minted for the dev host cannot be replayed at prod.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertAudience(array $claims, string $host): void
    {
        if (! hash_equals((string) ($claims['aud'] ?? ''), $host)) {
            throw new InvalidTokenException('Token audience mismatch.');
        }
    }

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertAllowedDomain(array $claims): void
    {
        $domain = (string) config('mmp.overwatch.allowed_domain');
        $email = strtolower((string) ($claims['sub'] ?? ''));

        if ($domain === '' || ! str_ends_with($email, '@'.strtolower($domain))) {
            throw new InvalidTokenException('Email domain not allowed.');
        }
    }

    /**
     * Reject replays: the first time a jti is seen it is cached for the token's
     * remaining lifetime; a second redemption finds it already present.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws InvalidTokenException
     */
    protected function assertSingleUse(array $claims): void
    {
        $jti = $claims['jti'] ?? null;

        if (! is_string($jti) || $jti === '') {
            throw new InvalidTokenException('Missing token id.');
        }

        // Replay protection requires a shared, persistent cache. The array/null
        // stores can't remember a jti across requests, so warn loudly.
        if (in_array(config('cache.default'), ['array', 'null'], true)) {
            Log::warning('Overwatch: replay protection is degraded — cache store ['.config('cache.default').'] is not shared/persistent. Use redis or database.');
        }

        // The token can only be replayed within its enforced lifetime, so the
        // jti only needs to be remembered for that window (independent of the
        // HQ-supplied exp, which we deliberately do not trust for sizing).
        $ttl = max(1, (int) config('mmp.overwatch.token_ttl', 60)) + self::CLOCK_SKEW;

        if (! Cache::add('overwatch:jti:'.$jti, true, $ttl)) {
            throw new InvalidTokenException('Token already used.');
        }
    }

    /**
     * @throws InvalidTokenException
     */
    protected function publicKey(): string
    {
        $configured = (string) config('mmp.overwatch.public_key');

        if ($configured === '') {
            throw new InvalidTokenException('Overwatch public key is not configured.');
        }

        $decoded = base64_decode($configured, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidTokenException('Overwatch public key is invalid.');
        }

        return $decoded;
    }

    protected function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
