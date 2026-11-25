<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ItemController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// AI 智慧尋物比對路由
Route::post('/ai-match', [AiController::class, 'aiMatch']);

// 物品公開讀取與刊登 API 路由
Route::get('/items', [ItemController::class, 'index']);
Route::get('/items/{id}', [ItemController::class, 'show']);
Route::post('/items', [ItemController::class, 'store']);

// 失物認領防誤刪機制（VLM 照片比對 + Email 通知）
Route::post('/items/{id}/verify-claim', [ItemController::class, 'verifyClaim']);

// 註冊與登入路由
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 登入狀態持久化驗證路由（Sanctum Bearer Token）
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/verify', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // 管理後台專屬路由（需 identity = '管理員'）
    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/admin/items', [ItemController::class, 'adminIndex']);
        Route::post('/admin/items/{id}/approve', [ItemController::class, 'adminApprove']);
        Route::post('/admin/items/{id}/reject', [ItemController::class, 'adminReject']);
        Route::delete('/items/{id}', [ItemController::class, 'destroy']);
        Route::delete('/admin/items/{id}', [ItemController::class, 'destroy']);
    });
});
