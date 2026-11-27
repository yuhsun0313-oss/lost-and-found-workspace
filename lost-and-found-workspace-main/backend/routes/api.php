<?php

use App\Http\Controllers\AccountController;
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

// 需登入憑證（Sanctum Bearer Token）之路由
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/verify', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // 刊登者自行編輯／刪除自己刊登中物品（管理員亦可使用）
    Route::put('/items/{id}', [ItemController::class, 'update']);
    Route::delete('/items/{id}', [ItemController::class, 'destroy']);

    // 帳號基本資料安全修改（更改 Email、更改密碼、更改使用者 ID）
    Route::prefix('account')->group(function () {
        Route::post('/email/send-old-code', [AccountController::class, 'sendOldEmailCode']);
        Route::post('/email/verify-old-code', [AccountController::class, 'verifyOldEmailCode']);
        Route::post('/email/send-new-code', [AccountController::class, 'sendNewEmailCode']);
        Route::post('/email/confirm', [AccountController::class, 'confirmEmailChange']);
        Route::post('/password/send-code', [AccountController::class, 'sendPasswordCode']);
        Route::post('/password/confirm', [AccountController::class, 'confirmPasswordChange']);
        Route::post('/username', [AccountController::class, 'updateUsername']);
    });

    // 管理後台專屬路由（需 identity = '管理員'）
    Route::middleware(EnsureAdmin::class)->group(function () {
        Route::get('/admin/items', [ItemController::class, 'adminIndex']);
        Route::post('/admin/items/{id}/approve', [ItemController::class, 'adminApprove']);
        Route::post('/admin/items/{id}/reject', [ItemController::class, 'adminReject']);
        Route::delete('/admin/items/{id}', [ItemController::class, 'destroy']);
    });
});
