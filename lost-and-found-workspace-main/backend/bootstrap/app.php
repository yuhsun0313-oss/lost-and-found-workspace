<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: __DIR__.'/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 🛡️ 強制攔截所有 API 錯誤，並加上 CORS 標頭，避免前端被 CORS 擋住而看不到真實 500 錯誤
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '伺服器發生錯誤：' . $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ], 500, [
                    'Access-Control-Allow-Origin' => '*',
                    'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                    'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With, Authorization, X-XSRF-TOKEN'
                ]);
            }
        });
    })->create();