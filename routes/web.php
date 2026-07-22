<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::view('users', 'users.index')
    ->middleware(['auth', 'permission:users,view'])
    ->name('users.index');

Route::view('roles', 'roles.index')
    ->middleware(['auth', 'permission:roles,view'])
    ->name('roles.index');

require __DIR__.'/auth.php';
