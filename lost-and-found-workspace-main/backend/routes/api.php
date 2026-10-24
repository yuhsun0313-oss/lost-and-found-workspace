<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\AiController;


// 新增 AI 智慧尋物比對路由
Route::post('/ai-match', [AiController::class, 'matchItems']);

// 物品相關 API 路由
Route::get('/items', [ItemController::class, 'index']);
Route::post('/items', [ItemController::class, 'store']);

// 刪除物品 API
Route::delete('/items/{id}', [ItemController::class, 'destroy']);
// 註冊與登入路由
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

