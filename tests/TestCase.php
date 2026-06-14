<?php

namespace ModernMcguire\Overwatch\Tests;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ModernMcguire\Overwatch\OverwatchServiceProvider;
use ModernMcguire\Overwatch\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The Ed25519 keypair generated fresh for each test run. HQ would hold the
     * secret key; the application under test is configured with the public key.
     *
     * @var array{public: string, secret: string}
     */
    protected array $keypair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUsersTable();
    }

    protected function getPackageProviders($app): array
    {
        return [OverwatchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->keypair = [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];

        $app['config']->set('cache.default', 'array');
        $app['config']->set('auth.providers.users.model', User::class);

        $app['config']->set('mmp.overwatch.public_key', $this->keypair['public']);
        $app['config']->set('mmp.overwatch.allowed_domain', 'modernmcguire.com');
        $app['config']->set('mmp.overwatch.redirect_to', '/dashboard');
        $app['config']->set('mmp.overwatch.token_ttl', 60);
    }

    protected function createUsersTable(bool $withIsAdmin = false): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) use ($withIsAdmin) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();

            if ($withIsAdmin) {
                $table->boolean('is_admin')->default(false);
            }

            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Mint a signed token exactly the way HQ does, for the test's keypair.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function mintToken(array $overrides = [], ?string $secret = null): string
    {
        $claims = array_merge([
            'iss' => 'mmp-hq',
            'aud' => 'localhost',
            'sub' => 'tommy@modernmcguire.com',
            'name' => 'Tommy McGuire',
            'env' => 'production',
            'purpose' => 'login',
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'exp' => time() + 60,
        ], $overrides);

        $secretKey = base64_decode($secret ?? $this->keypair['secret']);
        $payload = json_encode($claims);
        $signature = sodium_crypto_sign_detached($payload, $secretKey);

        return $this->base64Url($payload).'.'.$this->base64Url($signature);
    }

    protected function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
