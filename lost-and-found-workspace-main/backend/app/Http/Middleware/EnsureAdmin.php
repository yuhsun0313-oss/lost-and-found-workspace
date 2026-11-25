<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || $user->identity !== '管理員') {
            return response()->json([
                'success' => false,
                'message' => '權限不足，僅限管理員存取此功能。',
            ], 403);
        }

        return $next($request);
    }
}
