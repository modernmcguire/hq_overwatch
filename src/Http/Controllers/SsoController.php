<?php

namespace ModernMcguire\Overwatch\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use ModernMcguire\Overwatch\Exceptions\InvalidTokenException;
use ModernMcguire\Overwatch\Support\TokenVerifier;
use ModernMcguire\Overwatch\Support\UserProvisioner;

/**
 * The trusted SSO entry point. Verifies an HQ-signed login token, provisions
 * the user if needed, logs them in, and redirects to the configured landing
 * page. Any failure is a generic 403 — no detail leaks to a probing caller.
 */
class SsoController
{
    public function __invoke(Request $request, TokenVerifier $verifier, UserProvisioner $provisioner): RedirectResponse
    {
        try {
            $claims = $verifier->verify((string) $request->query('token'), 'login', $request->getHost());
        } catch (InvalidTokenException) {
            abort(403);
        }

        $user = $provisioner->provision($claims);

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->to((string) config('mmp.overwatch.redirect_to', '/'));
    }
}
