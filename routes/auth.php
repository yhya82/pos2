<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// No self-registration route: per the SRS, accounts are created by an
// Administrator (Phase 01's user management screens), not signed up for.
// No email-verification routes either — the schema has no
// email_verified_at column, and staff accounts don't need it.

Route::middleware('guest')->group(function () {
    Volt::route('login', 'pages.auth.login')
        ->name('login');

    Volt::route('forgot-password', 'pages.auth.forgot-password')
        ->name('password.request');

    Volt::route('reset-password/{token}', 'pages.auth.reset-password')
        ->name('password.reset');
});

Route::middleware('auth')->group(function () {
    Volt::route('confirm-password', 'pages.auth.confirm-password')
        ->name('password.confirm');
});
