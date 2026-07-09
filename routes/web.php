<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Phase 0: these routes just serve the static frontend shell pages.
| From Phase 1 onward, some of these will be replaced by controller
| methods that check auth state before returning a view.
|
*/

Route::get('/', function () {
    return file_get_contents(public_path('index.html'));
});

Route::get('/login', function () {
    return file_get_contents(public_path('login.html'));
});

Route::get('/signup', function () {
    return file_get_contents(public_path('signup.html'));
});

Route::get('/dashboard', function () {
    // Phase 1 will wrap this in an auth middleware check.
    return file_get_contents(public_path('dashboard.html'));
});

Route::get('/verify-identity', function () {
    return file_get_contents(public_path('verify-identity.html'));
});

Route::get('/transfer', function () {
    // Phase 3: send-money page. Auth + KYC-tier gating happens
    // client-side (transfer.js) against /api/auth/me, same pattern as
    // /verify-identity above.
    return file_get_contents(public_path('transfer.html'));
});

Route::get('/activity', function () {
    // Phase 3: transaction history page.
    return file_get_contents(public_path('activity.html'));
});

Route::get('/admin/kyc-queue', function () {
    return file_get_contents(public_path('admin/kyc-queue.html'));
});
