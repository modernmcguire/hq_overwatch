<?php

it('returns capability for a signed request', function () {
    $token = $this->mintToken(['purpose' => 'status', 'sub' => null]);

    $this->getJson('/mmp/overwatch/status?token='.$token)
        ->assertOk()
        ->assertJson(['overwatch' => true])
        ->assertJsonStructure(['overwatch', 'version']);
});

it('rejects an unsigned status request', function () {
    $this->getJson('/mmp/overwatch/status')->assertForbidden();
});

it('rejects a status request signed by an unknown key', function () {
    $stranger = sodium_crypto_sign_keypair();
    $token = $this->mintToken(
        ['purpose' => 'status'],
        base64_encode(sodium_crypto_sign_secretkey($stranger))
    );

    $this->getJson('/mmp/overwatch/status?token='.$token)->assertForbidden();
});

it('rejects a login token at the status endpoint', function () {
    $token = $this->mintToken(['purpose' => 'login']);

    $this->getJson('/mmp/overwatch/status?token='.$token)->assertForbidden();
});

it('does not leak environment or config to callers', function () {
    $token = $this->mintToken(['purpose' => 'status']);

    $json = $this->getJson('/mmp/overwatch/status?token='.$token)->json();

    expect($json)->not->toHaveKey('env')
        ->and($json)->not->toHaveKey('sso');
});
