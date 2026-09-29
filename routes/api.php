<?php

use App\Http\Controllers\AskController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PosCatalogController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RefundController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:registration');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/shops', [ShopController::class, 'store']);
    Route::get('/shops/{shop}', [ShopController::class, 'show']);
    Route::patch('/shops/{shop}', [ShopController::class, 'update']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('shop.access')->group(function () {
        Route::get('/pos/products', [PosCatalogController::class, 'index']);

        Route::get('/reports/dashboard', [ReportController::class, 'dashboard']);

        Route::get('/sales', [SaleController::class, 'index']);
        Route::post('/sales', [SaleController::class, 'store']);
        Route::get('/sales/{sale}', [SaleController::class, 'show']);

        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::post('/customers/{customer}/payments', [CustomerController::class, 'pay']);

        Route::post('/shifts/open', [ShiftController::class, 'open']);
        Route::get('/shifts/current', [ShiftController::class, 'current']);
        Route::get('/shifts', [ShiftController::class, 'index']);
        Route::get('/shifts/{shift}', [ShiftController::class, 'show']);
        Route::post('/shifts/{shift}/close', [ShiftController::class, 'close']);

        Route::post('/sync/push', [SyncController::class, 'push']);
        Route::get('/sync/pull', [SyncController::class, 'pull']);
    });

    Route::middleware('shop.access:owner,manager')->group(function () {
        Route::post('/sales/{sale}/void', [SaleController::class, 'void']);
        Route::get('/sales/{sale}/refundable', [RefundController::class, 'refundable']);
        Route::post('/sales/{sale}/refund', [RefundController::class, 'store']);
        Route::patch('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/ledger', [CustomerController::class, 'ledger']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::post('/products/import', [ProductImportController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::patch('/products/{product}', [ProductController::class, 'update']);
        Route::post('/products/{product}/archive', [ProductController::class, 'archive']);
        Route::post('/products/{product}/restore', [ProductController::class, 'restore']);

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::patch('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/movements', [InventoryController::class, 'movements']);
        Route::post('/inventory/adjustments', [InventoryController::class, 'adjust']);
        Route::post('/inventory/damage', [InventoryController::class, 'damage']);
        Route::post('/inventory/loss', [InventoryController::class, 'loss']);
        Route::post('/inventory/opening-stock', [InventoryController::class, 'openingStock']);

        Route::get('/suppliers', [SupplierController::class, 'index']);
        Route::post('/suppliers', [SupplierController::class, 'store']);
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
        Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);
        Route::get('/suppliers/{supplier}/ledger', [SupplierController::class, 'ledger']);
        Route::post('/suppliers/{supplier}/payments', [SupplierController::class, 'pay']);

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);

        Route::get('/ask/status', [AskController::class, 'status']);
        Route::post('/ask', [AskController::class, 'ask'])->middleware('throttle:ask');

        Route::get('/reports/sales', [ReportController::class, 'sales']);
        Route::get('/reports/stock', [ReportController::class, 'stock']);
        Route::get('/reports/debt', [ReportController::class, 'debt']);
        Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf']);
        Route::get('/reports/export/csv', [ReportController::class, 'exportCsv']);

        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::patch('/staff/{user}', [StaffController::class, 'update']);
        Route::post('/staff/{user}/password', [StaffController::class, 'resetPassword']);
    });

    Route::middleware('shop.access:owner')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::get('/reports/profit', [ReportController::class, 'profit']);
    });
});
