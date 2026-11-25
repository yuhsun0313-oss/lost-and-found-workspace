<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * 註冊邏輯（預設身分為「一般成員」）
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:users',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'identity' => '一般成員',
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'success' => true,
            'message' => '註冊成功！',
            'user' => [
                'id' => $user->id,
                'identity' => $user->identity,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    /**
     * 登入邏輯：支援「名稱」或「Email」登入並核發 Sanctum Auth Token
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $loginField = trim($request->input('login'));
        $password = $request->input('password');
        $field = filter_var($loginField, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        $user = User::where($field, $loginField)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => '帳號或密碼錯誤！',
            ], 401);
        }

        // 核發 Sanctum Bearer Token，供前端 localStorage 保存與 F5 持久化驗證
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => '登入成功！',
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'identity' => $user->identity ?? '一般成員',
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    /**
     * 驗證目前 Token 有效性並回傳使用者資訊與身分 (/api/me, /api/verify)
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'valid' => false,
                'message' => '登入憑證無效或已過期',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'valid' => true,
            'user' => [
                'id' => $user->id,
                'identity' => $user->identity ?? '一般成員',
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    /**
     * 登出並註銷目前使用的 Token
     */
    public function logout(Request $request)
    {
        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->json([
            'success' => true,
            'message' => '已成功登出',
        ], 200);
    }
}
