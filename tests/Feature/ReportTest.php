<?php

it('returns the report for a token signed for it', function () {
    $token = $this->mintToken(['purpose' => 'report', 'sub' => null]);

    $this->getJson('/mmp/overwatch/report?token='.$token)
        ->assertOk()
        ->assertJson(['overwatch' => true, 'php' => PHP_VERSION])
        ->assertJsonStructure(['overwatch', 'php', 'laravel', 'livewire', 'composer', 'backups' => ['enabled', 'last_run_at'], 'errors']);
});

it('rejects an unsigned report request', function () {
    $this->getJson('/mmp/overwatch/report')->assertForbidden();
});

it('rejects a status or login token at the report endpoint', function (string $purpose) {
    $this->getJson('/mmp/overwatch/report?token='.$this->mintToken(['purpose' => $purpose]))->assertForbidden();
})->with(['status', 'login']);

it('rejects a report token signed by an unknown key', function () {
    $stranger = sodium_crypto_sign_keypair();
    $token = $this->mintToken(['purpose' => 'report'], base64_encode(sodium_crypto_sign_secretkey($stranger)));

    $this->getJson('/mmp/overwatch/report?token='.$token)->assertForbidden();
});

it('refuses a report token that was already used', function () {
    $token = $this->mintToken(['purpose' => 'report']);

    $this->getJson('/mmp/overwatch/report?token='.$token)->assertOk();
    $this->getJson('/mmp/overwatch/report?token='.$token)->assertForbidden();
});

it('lists the top-level composer packages, not their dependencies', function () {
    $token = $this->mintToken(['purpose' => 'report']);

    $names = collect($this->getJson('/mmp/overwatch/report?token='.$token)->json('composer'))->pluck('name');

    expect($names)->not->toContain('php')
        ->and($names->filter(fn ($name) => str_starts_with($name, 'ext-')))->toBeEmpty();
});

it('reports only the first line of recent errors, newest first', function () {
    $log = storage_path('logs/laravel.log');
    @mkdir(dirname($log), 0777, true);
    file_put_contents($log, implode("\n", [
        '[2026-10-23 08:00:00] production.ERROR: First failure {"exception":"[object] (secret)"}',
        '#0 /var/www/app/Secret.php(12): stack frame that must not be sent',
        '[2026-10-23 09:00:00] production.INFO: Not an error',
        '[2026-10-23 10:00:00] production.CRITICAL: Latest failure',
    ])."\n");

    $errors = $this->getJson('/mmp/overwatch/report?token='.$this->mintToken(['purpose' => 'report']))->json('errors');

    expect($errors)->toHaveCount(2)
        ->and($errors[0]['message'])->toBe('Latest failure')
        ->and($errors[0]['level'])->toBe('critical')
        ->and(json_encode($errors))->not->toContain('Secret.php');

    @unlink($log);
});
