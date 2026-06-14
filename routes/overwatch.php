<?php

use Illuminate\Support\Facades\Route;
use ModernMcguire\Overwatch\Http\Controllers\SsoController;
use ModernMcguire\Overwatch\Http\Controllers\StatusController;

// Signed capability + connection check — HQ pings this (with a signed token) to
// learn whether the project is reachable and holds the correct key. Returns
// nothing to unauthenticated callers.
Route::get('status', StatusController::class)->name('mmp.overwatch.status');

// Real SSO entry point — consumes an HQ-signed login token.
Route::get('sso', SsoController::class)->name('mmp.overwatch.sso');
