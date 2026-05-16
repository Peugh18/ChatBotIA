<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\CategoryController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // CRM Chatbot
    Route::get('crm', [DashboardController::class, 'index'])->name('crm');
    Route::get('crm/chat/{client}', [DashboardController::class, 'show'])->name('crm.chat');
    Route::post('crm/chat/{client}/status', [DashboardController::class, 'updateStatus'])->name('crm.client.status');
    Route::post('crm/chat/{client}/order', [DashboardController::class, 'createOrder'])->name('crm.client.order');

    // Inventory CRUD
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory');
    Route::post('inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::put('inventory/{product}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::delete('inventory/{product}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

    // Categories CRUD
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Sales Dashboard
    Route::get('dashboard', [DashboardController::class, 'sales'])->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
