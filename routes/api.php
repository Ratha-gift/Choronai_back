<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CrudController;
use App\Http\Controllers\FrontendWorkspaceController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\RewardController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockDocumentController;
use App\Http\Controllers\StockLevelController;
use App\Http\Controllers\SuspendedSaleController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Models\MemberCard;
use App\Support\Resources;
use Illuminate\Support\Facades\Route;

// Everything is under /api/v1 (see bootstrap/app.php).
// `perm:a,b` = the user's group needs permission a or b (Settings → Group Permissions).

Route::post('auth/login', [AuthController::class, 'login']);

// Images are public like any website image (random names). Upload needs a sign-in.
Route::get('images/{path}', [ImageController::class, 'show'])->where('path', '.*')->name('images.show');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // The Next.js frontend's collections (see FrontendWorkspaceController).
    Route::get('frontend/workspace', [FrontendWorkspaceController::class, 'show']);
    Route::post('frontend/workspace', [FrontendWorkspaceController::class, 'commit']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('audit/logins', [AuthController::class, 'loginHistory'])->middleware('perm:settings.system,users.view');
    Route::get('audit/activity', [AuditController::class, 'activity'])->middleware('perm:settings.system,users.view');
    Route::get('audit/history/{id}', [AuditController::class, 'history']);

    // People
    Route::get('member-cards/{card}/history', [RewardController::class, 'history'])->middleware('perm:memberCards.view');
    Route::post('member-cards/{card}/redeem', [RewardController::class, 'redeem'])->middleware('perm:memberCards.redeem');
    Route::get('users', [UserController::class, 'index'])->middleware('perm:users.view');
    Route::get('users/{user}/registers', [UserController::class, 'registers'])->middleware('perm:users.view');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('perm:users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('perm:users.add');
    Route::patch('users/{user}', [UserController::class, 'update'])->middleware('perm:users.edit');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('perm:users.delete');

    Route::get('user-groups', [UserGroupController::class, 'index'])->middleware('perm:users.view');
    Route::post('user-groups', [UserGroupController::class, 'store'])->middleware('perm:users.add');
    Route::patch('user-groups/{group}', [UserGroupController::class, 'update'])->middleware('perm:users.edit');
    Route::delete('user-groups/{group}', [UserGroupController::class, 'destroy'])->middleware('perm:users.delete');
    Route::get('user-groups/{group}/permissions', [UserGroupController::class, 'permissions'])->middleware('perm:users.view');
    Route::put('user-groups/{group}/permissions', [UserGroupController::class, 'updatePermissions'])->middleware('perm:users.edit');

    // Settings
    Route::get('settings/{scope}', [SettingsController::class, 'show']);
    Route::put('settings/{scope}', [SettingsController::class, 'update']);
    Route::post('settings/logo', [SettingsController::class, 'logo']);

    // Images (Cloudflare R2 when configured): products, business-images,
    // business-branch-images, user-profile. ImageController checks the permission.
    Route::post('uploads/images', [ImageController::class, 'store']);
    Route::delete('uploads/images/{path}', [ImageController::class, 'destroy'])->where('path', '.*');

    // Simple lists: branches, warehouses, customers, currencies, ... (App\Support\Resources).
    // Reading only needs a signed-in user; CrudController checks the permission to change.
    $lists = implode('|', array_keys(Resources::all()));
    Route::get('{resource}', [CrudController::class, 'index'])->where('resource', $lists);
    Route::post('{resource}', [CrudController::class, 'store'])->where('resource', $lists);
    Route::get('{resource}/{id}', [CrudController::class, 'show'])->where('resource', $lists)->whereUuid('id');
    Route::patch('{resource}/{id}', [CrudController::class, 'update'])->where('resource', $lists)->whereUuid('id');
    Route::delete('{resource}/{id}', [CrudController::class, 'destroy'])->where('resource', $lists)->whereUuid('id');
    Route::get('member-cards/by-code/{cardNo}', fn (string $cardNo) => MemberCard::with('customer')->where('card_no', $cardNo)->firstOrFail());

    // Products (stock changes only through stock documents and sales)
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::post('products', [ProductController::class, 'store'])->middleware('perm:products.add');
    Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('perm:products.edit');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('perm:products.delete');
    Route::get('products/{product}/price-history', [ProductController::class, 'priceHistory'])->middleware('perm:products.view');
    Route::get('products/{product}/movements', [ProductController::class, 'movements'])->middleware('perm:stock.view,products.view');

    // Stock Management
    Route::post('stock/adjustments/{id}/approve', [StockDocumentController::class, 'approve'])->middleware('perm:stock.approve');
    Route::post('stock/issues/{id}/receive', [StockDocumentController::class, 'receiveTransfer'])->middleware('perm:stock.receive');
    Route::post('stock/issues/{id}/short', [StockDocumentController::class, 'reportShort'])->middleware('perm:stock.receive');
    Route::post('stock/issues/{id}/accept', [StockDocumentController::class, 'accept'])->middleware('perm:stock.approve');
    Route::post('stock/issues/{id}/reject', [StockDocumentController::class, 'reject'])->defaults('kind', 'issue')->middleware('perm:stock.approve');
    Route::post('stock/adjustments/{id}/reject', [StockDocumentController::class, 'reject'])->defaults('kind', 'adjustment')->middleware('perm:stock.approve');
    Route::get('stock/levels', [StockLevelController::class, 'index'])->middleware('perm:stock.view,products.view');
    foreach (['receives' => ['receive', 'stock.receive'], 'issues' => ['issue', 'stock.issue'], 'adjustments' => ['adjustment', 'stock.add']] as $path => [$kind, $create]) {
        Route::prefix("stock/{$path}")->controller(StockDocumentController::class)->group(function () use ($kind, $create) {
            Route::get('/', 'index')->defaults('kind', $kind)->middleware('perm:stock.view');
            Route::get('{id}', 'show')->defaults('kind', $kind)->middleware('perm:stock.view');
            Route::post('/', 'store')->defaults('kind', $kind)->middleware("perm:{$create}");
            Route::put('{id}', 'update')->defaults('kind', $kind)->middleware('perm:stock.edit');
            Route::delete('{id}', 'destroy')->defaults('kind', $kind)->middleware('perm:stock.delete');
        });
    }

    // POS
    Route::middleware('perm:sales.pos')->group(function () {
        Route::get('registers/current', [RegisterController::class, 'current']);
        Route::post('registers/open', [RegisterController::class, 'open']);
        Route::post('registers/{register}/close', [RegisterController::class, 'close']);
        Route::get('registers/{register}/summary', [RegisterController::class, 'summary']);
        Route::post('sales', [SaleController::class, 'store']);
        Route::get('suspended-sales', [SuspendedSaleController::class, 'index']);
        Route::post('suspended-sales', [SuspendedSaleController::class, 'store']);
        Route::delete('suspended-sales/{suspendedSale}', [SuspendedSaleController::class, 'destroy']);
        Route::post('pos/verify-pin', [PosController::class, 'verifyPin']);
        Route::get('pos/bootstrap', [PosController::class, 'bootstrap']);
        Route::post('pos/sync', [PosController::class, 'sync']);
    });
    Route::get('registers', [RegisterController::class, 'index']);
    Route::get('pos/devices', [PosController::class, 'devices'])->middleware('perm:settings.pos,settings.edit');
    Route::post('pos/devices', [PosController::class, 'registerDevice'])->middleware('perm:settings.pos,settings.edit');

    // Sales
    Route::get('sales', [SaleController::class, 'index'])->middleware('perm:sales.view');
    Route::get('sales/{sale}', [SaleController::class, 'show'])->middleware('perm:sales.view,sales.pos');
    Route::delete('sales/{sale}', [SaleController::class, 'destroy'])->middleware('perm:sales.delete,sales.cancel');
    Route::post('sales/{sale}/review', [SaleController::class, 'review'])->middleware('perm:sales.edit');
    Route::post('sales/{sale}/payments', [SaleController::class, 'addPayment'])->middleware('perm:sales.payments');
    Route::get('sale-returns', [SaleReturnController::class, 'index'])->middleware('perm:sales.view,sales.returns');
    Route::get('sale-returns/{saleReturn}', [SaleReturnController::class, 'show'])->middleware('perm:sales.view,sales.returns');
    Route::post('sale-returns', [SaleReturnController::class, 'store'])->middleware('perm:sales.returns');
    Route::delete('sale-returns/{saleReturn}', [SaleReturnController::class, 'destroy'])->middleware('perm:sales.delete');
});
