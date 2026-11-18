<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Middleware\EnsureOwner;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // ---- Owner and staff -------------------------------------------------
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/ingredients', [IngredientController::class, 'index']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);

    // ---- Owner only ------------------------------------------------------
    Route::middleware(EnsureOwner::class)->group(function () {
        Route::get('/dashboard', DashboardController::class);

        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);

        Route::post('/ingredients', [IngredientController::class, 'store']);
        Route::put('/ingredients/{ingredient}', [IngredientController::class, 'update']);
        Route::delete('/ingredients/{ingredient}', [IngredientController::class, 'destroy']);
        Route::post('/ingredients/{ingredient}/restock', [IngredientController::class, 'restock']);
        Route::post('/ingredients/{ingredient}/adjust', [IngredientController::class, 'adjust']);
        Route::get('/ingredients/{ingredient}/movements', [IngredientController::class, 'movements']);

        Route::post('/orders/{order}/void', [OrderController::class, 'void']);

        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
    });
});
