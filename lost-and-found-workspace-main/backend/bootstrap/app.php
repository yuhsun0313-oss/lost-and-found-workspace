<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: __DIR__.'/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // API 使用 Bearer Token 無須重導向至 login 頁面
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $corsHeaders = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With, Authorization, X-XSRF-TOKEN, Accept',
        ];

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($corsHeaders) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '登入憑證無效或已過期，請重新登入。',
                ], 401, $corsHeaders);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($corsHeaders) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => '資料驗證失敗',
                    'errors' => $e->errors(),
                ], 422, $corsHeaders);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) use ($corsHeaders) {
            if ($request->is('api/*')) {
                $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
                return response()->json([
                    'success' => false,
                    'message' => $status === 500 ? ('伺服器發生錯誤：' . $e->getMessage()) : $e->getMessage(),
                ], $status, $corsHeaders);
            }
        });
    })->create();
