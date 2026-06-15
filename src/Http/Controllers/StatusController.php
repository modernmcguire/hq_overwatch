<?php

namespace ModernMcguire\Overwatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ModernMcguire\Overwatch\Exceptions\InvalidTokenException;
use ModernMcguire\Overwatch\Overwatch;
use ModernMcguire\Overwatch\Support\TokenVerifier;

/**
 * Capability + connection check. Requires an HQ-signed "status" token, so it
 * discloses nothing to unauthenticated callers: a valid signature proves both
 * that this app is reachable AND that it holds the correct public key. No user
 * is created and no session is started.
 */
class StatusController
{
    public function __invoke(Request $request, TokenVerifier $verifier): JsonResponse
    {
        try {
            $verifier->verify((string) $request->query('token'), 'status', $request->getHost());
        } catch (InvalidTokenException) {
            return response()->json(['overwatch' => false], 403);
        }

        return response()->json([
            'overwatch' => true,
            'version' => Overwatch::version(),
        ]);
    }
}
