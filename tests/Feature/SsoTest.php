<?php

use ModernMcguire\Overwatch\Tests\Fixtures\User;

it('logs in and provisions a new user from a valid token', function () {
    $token = $this->mintToken();

    $this->get('/mmp/overwatch/sso?token='.$token)
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'tommy@modernmcguire.com']);
});

it('reuses an existing user instead of duplicating', function () {
    User::create(['name' => 'Existing', 'email' => 'tommy@modernmcguire.com', 'password' => bcrypt('x')]);

    $this->get('/mmp/overwatch/sso?token='.$this->mintToken())
        ->assertRedirect('/dashboard');

    expect(User::where('email', 'tommy@modernmcguire.com')->count())->toBe(1);
});

it('elevates new users via an is_admin column when present', function () {
    $this->createUsersTable(withIsAdmin: true);

    $this->get('/mmp/overwatch/sso?token='.$this->mintToken())->assertRedirect();

    $this->assertDatabaseHas('users', ['email' => 'tommy@modernmcguire.com', 'is_admin' => true]);
});

it('honours a provision_user override closure', function () {
    config()->set('mmp.overwatch.provision_user', function (array $claims) {
        return User::create([
            'name' => 'Overridden',
            'email' => $claims['sub'],
            'password' => bcrypt('x'),
        ]);
    });

    $this->get('/mmp/overwatch/sso?token='.$this->mintToken())->assertRedirect();

    $this->assertDatabaseHas('users', ['name' => 'Overridden']);
});

it('rejects an expired token', function () {
    $token = $this->mintToken(['iat' => time() - 600, 'exp' => time() - 300]);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects a token whose email is outside the allowed domain', function () {
    $token = $this->mintToken(['sub' => 'attacker@gmail.com']);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects a tampered token', function () {
    $token = $this->mintToken();

    $this->get('/mmp/overwatch/sso?token='.$token.'x')->assertForbidden();
    $this->assertGuest();
});

it('rejects a token bound to a different host', function () {
    $token = $this->mintToken(['aud' => 'prod.example.com']);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects a replayed token (single use)', function () {
    $token = $this->mintToken();

    $this->get('/mmp/overwatch/sso?token='.$token)->assertRedirect();

    // Fresh guest session, same token — must be refused.
    $this->app['auth']->guard()->logout();
    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
});

it('rejects a verify challenge at the sso endpoint', function () {
    $token = $this->mintToken(['purpose' => 'verify']);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects an oversized token before doing any crypto', function () {
    $this->get('/mmp/overwatch/sso?token='.str_repeat('a', 5000))->assertForbidden();
    $this->assertGuest();
});

it('rejects a future-dated token', function () {
    $token = $this->mintToken(['iat' => time() + 120, 'exp' => time() + 180]);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects a token older than the configured ttl even if exp is still future', function () {
    $token = $this->mintToken(['iat' => time() - 600, 'exp' => time() + 600]);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});

it('rejects a token with an unexpected issuer', function () {
    $token = $this->mintToken(['iss' => 'evil']);

    $this->get('/mmp/overwatch/sso?token='.$token)->assertForbidden();
    $this->assertGuest();
});
