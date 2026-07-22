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

Route::view('categories', 'categories.index')
    ->middleware(['auth', 'permission:categories,view'])
    ->name('categories.index');

// Units has no dedicated permission catalog entry (it's a product-config
// concern, not its own SRS module) — gated under products,view instead.
Route::view('units', 'units.index')
    ->middleware(['auth', 'permission:products,view'])
    ->name('units.index');

Route::view('suppliers', 'suppliers.index')
    ->middleware(['auth', 'permission:suppliers,view'])
    ->name('suppliers.index');

Route::view('products', 'products.index')
    ->middleware(['auth', 'permission:products,view'])
    ->name('products.index');

Route::view('purchase-orders', 'purchase-orders.index')
    ->middleware(['auth', 'permission:purchase_orders,view', 'module:purchase_management'])
    ->name('purchase-orders.index');

require __DIR__.'/auth.php';
