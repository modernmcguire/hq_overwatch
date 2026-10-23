<?php

namespace ModernMcguire\Overwatch\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ModernMcguire\Overwatch\Exceptions\InvalidTokenException;
use ModernMcguire\Overwatch\Support\SiteReport;
use ModernMcguire\Overwatch\Support\TokenVerifier;

/**
 * What this app is running: versions, top-level composer packages, backups and
 * recent errors, for HQ's site page. Requires an HQ-signed "report" token, so
 * it discloses nothing to unauthenticated callers (same rule as /status).
 */
class ReportController
{
    public function __invoke(Request $request, TokenVerifier $verifier, SiteReport $report): JsonResponse
    {
        try {
            $verifier->verify((string) $request->query('token'), 'report', $request->getHost());
        } catch (InvalidTokenException) {
            return response()->json(['overwatch' => false], 403);
        }

        return response()->json($report->build());
    }
}
