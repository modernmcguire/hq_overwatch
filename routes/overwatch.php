<?php

use Illuminate\Support\Facades\Route;
use ModernMcguire\Overwatch\Http\Controllers\ReportController;
use ModernMcguire\Overwatch\Http\Controllers\SsoController;
use ModernMcguire\Overwatch\Http\Controllers\StatusController;

// Signed capability + connection check — HQ pings this (with a signed token) to
// learn whether the project is reachable and holds the correct key. Returns
// nothing to unauthenticated callers.
Route::get('status', StatusController::class)->name('mmp.overwatch.status');

// Signed report — HQ pulls versions, composer packages, backups and recent
// errors for its site page. Requires a "report" token; 403 otherwise.
Route::get('report', ReportController::class)->name('mmp.overwatch.report');

// Real SSO entry point — consumes an HQ-signed login token.
Route::get('sso', SsoController::class)->name('mmp.overwatch.sso');
