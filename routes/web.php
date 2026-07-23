<?php

use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReturnReceiptController;
use App\Models\Customer;
use App\Models\GeneralSetting;
use App\Models\HardwareSetting;
use App\Models\Product;
use Illuminate\Http\Request;
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

Route::get('products/{product}', fn (Product $product) => view('products.show', ['product' => $product]))
    ->middleware(['auth', 'permission:products,view'])
    ->name('products.show');

Route::view('purchase-orders', 'purchase-orders.index')
    ->middleware(['auth', 'permission:purchase_orders,view', 'module:purchase_management'])
    ->name('purchase-orders.index');

Route::view('inventory', 'inventory.index')
    ->middleware(['auth', 'permission:inventory,view'])
    ->name('inventory.index');

Route::view('pos', 'pos.index')
    ->middleware(['auth', 'permission:sales,create'])
    ->name('pos.index');

Route::view('sales', 'sales.index')
    ->middleware(['auth', 'permission:sales,view'])
    ->name('sales.index');

Route::get('sales/{sale}/receipt', [ReceiptController::class, 'show'])
    ->middleware(['auth'])
    ->name('sales.receipt');

Route::view('customers', 'customers.index')
    ->middleware(['auth', 'permission:customers,view'])
    ->name('customers.index');

Route::get('customers/{customer}', fn (Customer $customer) => view('customers.show', ['customer' => $customer]))
    ->middleware(['auth', 'permission:customers,view'])
    ->name('customers.show');

Route::view('returns', 'returns.index')
    ->middleware(['auth', 'permission:returns,view', 'module:return_management'])
    ->name('returns.index');

Route::get('returns/{salesReturn}/receipt', [ReturnReceiptController::class, 'show'])
    ->middleware(['auth'])
    ->name('returns.receipt');

Route::view('notifications', 'notifications.index')
    ->middleware(['auth', 'module:notifications'])
    ->name('notifications.index');

Route::view('reports', 'reports.index')
    ->middleware(['auth', 'permission:reports,view'])
    ->name('reports.index');

Route::view('audit-logs', 'audit-logs.index')
    ->middleware(['auth', 'permission:audit_logs,view'])
    ->name('audit-logs.index');

Route::view('settings', 'settings.index')
    ->middleware(['auth', 'permission:settings,view'])
    ->name('settings.index');

Route::get('settings/print-test', function (Request $request) {
    $hardware = HardwareSetting::current();
    $requested = $request->query('paper_size');

    return view('settings.print-test', [
        'general' => GeneralSetting::current(),
        'paperSize' => in_array($requested, ['58mm', '80mm', 'A4'], true) ? $requested : ($hardware?->paper_size ?? '80mm'),
        'printerName' => $hardware?->default_printer_name,
    ]);
})->middleware(['auth', 'permission:settings,view'])->name('settings.print-test');

require __DIR__.'/auth.php';
