<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    // 註冊邏輯
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:users', // 確保名稱不能重複
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return response()->json([
            'message' => '註冊成功！',
            'user' => $user
        ], 201);
    }

    // 登入邏輯：支援輸入「名稱」或「Email」來登入
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string', // 接收前端傳來的 login 欄位（可以是名字或信箱）
            'password' => 'required|string',
        ]);

        $loginField = $request->input('login');
        $password = $request->input('password');

        // 判斷使用者輸入的是 Email 還是 名稱 (name)
        $field = filter_var($loginField, FILTER_VALIDATE_EMAIL) ? 'email' : 'name';

        // 嘗試進行登入驗證
        if (Auth::attempt([$field => $loginField, 'password' => $password])) {
            $user = Auth::user();
            return response()->json([
                'message' => '登入成功！',
                'user' => $user
            ]);
        }

        return response()->json([
            'message' => '帳號或密碼錯誤！'
        ], 401);
    }
}