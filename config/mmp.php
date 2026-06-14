<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MMP Overwatch
    |--------------------------------------------------------------------------
    |
    | Overwatch lets MMP HQ sign a short-lived token that logs an MMP staff
    | member straight into this application. HQ holds the private key; this
    | app only ever holds the public key issued to it by HQ's "Link" wizard.
    |
    */

    'overwatch' => [

        // The Ed25519 public key (base64) issued to THIS project by MMP HQ.
        // Paste the value from HQ's link wizard into MMP_OVERWATCH_PUBLIC_KEY.
        'public_key' => env('MMP_OVERWATCH_PUBLIC_KEY'),

        // Only emails on this domain may be signed in via Overwatch.
        'allowed_domain' => env('MMP_OVERWATCH_DOMAIN', 'modernmcguire.com'),

        // Where to send the user after a successful SSO login.
        'redirect_to' => env('MMP_OVERWATCH_REDIRECT', '/'),

        // Maximum age (seconds) of an accepted token. Keep this small.
        'token_ttl' => (int) env('MMP_OVERWATCH_TOKEN_TTL', 60),

        // URI prefix the package routes are mounted under.
        'route_prefix' => env('MMP_OVERWATCH_PREFIX', 'mmp/overwatch'),

        // Optional override for how a user is found / created / elevated.
        // Set to a callable: fn (array $claims): \Illuminate\Contracts\Auth\Authenticatable
        // When null, the default provisioner is used (see UserProvisioner).
        'provision_user' => null,

    ],

];
