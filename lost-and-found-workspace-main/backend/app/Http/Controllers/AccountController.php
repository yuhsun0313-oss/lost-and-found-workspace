<?php

namespace App\Http\Controllers;

use App\Mail\VerificationCodeMail;
use App\Services\MailDispatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    private function generateSixDigitCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function createAndSendCode(int $userId, string $purpose, string $targetEmail, string $actionLabel, string $userName): array
    {
        $code = $this->generateSixDigitCode();

        // 清除同使用者同用途的舊驗證碼
        DB::table('verification_codes')
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->delete();

        DB::table('verification_codes')->insert([
            'user_id' => $userId,
            'purpose' => $purpose,
            'target_email' => $targetEmail,
            'code' => $code,
            'verified_at' => null,
            'expires_at' => Carbon::now()->addMinutes(10),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return MailDispatcher::send(
            $targetEmail,
            new VerificationCodeMail($code, $actionLabel, $userName)
        );
    }

    /**
     * 更改 Email - 步驟 1：驗證目前密碼並寄送驗證碼至「原 Email」
     */
    public function sendOldEmailCode(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
        ]);

        $user = $request->user();
        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => '目前密碼不正確！',
            ], 422);
        }

        $mailResult = $this->createAndSendCode(
            $user->id,
            'email_old',
            $user->email,
            '變更 Email（原信箱確認）',
            $user->name
        );

        if (!$mailResult['sent']) {
            return response()->json([
                'success' => false,
                'message' => '寄送原 Email 驗證碼失敗：' . ($mailResult['error'] ?? '請檢查寄信設定'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "驗證碼已寄送至您的原信箱（{$user->email}），請查收並輸入 6 位數驗證碼。",
        ], 200);
    }

    /**
     * 更改 Email - 步驟 2：驗證「原 Email 驗證碼」
     */
    public function verifyOldEmailCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $record = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'email_old')
            ->where('target_email', $user->email)
            ->orderByDesc('id')
            ->first();

        if (!$record || Carbon::parse($record->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => '原信箱驗證碼不存在或已過期，請重新發送。',
            ], 422);
        }

        if ($record->code !== trim($request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => '原信箱驗證碼不正確！',
            ], 422);
        }

        DB::table('verification_codes')
            ->where('id', $record->id)
            ->update([
                'verified_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => '原信箱驗證通過，請輸入您要綁定的新 Email。',
        ], 200);
    }

    /**
     * 更改 Email - 步驟 3：寄送驗證碼至「新 Email」
     */
    public function sendNewEmailCode(Request $request)
    {
        $user = $request->user();

        $oldVerified = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'email_old')
            ->whereNotNull('verified_at')
            ->where('expires_at', '>', Carbon::now()->subMinutes(15))
            ->exists();

        if (!$oldVerified) {
            return response()->json([
                'success' => false,
                'message' => '請先完成原 Email 驗證步驟。',
            ], 403);
        }

        $request->validate([
            'new_email' => 'required|email|max:255|unique:users,email',
        ], [
            'new_email.unique' => '此新 Email 已被其他帳號使用！',
        ]);

        $newEmail = trim($request->input('new_email'));
        $mailResult = $this->createAndSendCode(
            $user->id,
            'email_new',
            $newEmail,
            '變更 Email（新信箱確認）',
            $user->name
        );

        if (!$mailResult['sent']) {
            return response()->json([
                'success' => false,
                'message' => '寄送新 Email 驗證碼失敗：' . ($mailResult['error'] ?? '請檢查寄信設定'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "驗證碼已寄送至新信箱（{$newEmail}），請輸入驗證碼以完成更改。",
        ], 200);
    }

    /**
     * 更改 Email - 步驟 4：驗證「新 Email 驗證碼」並完成更改
     */
    public function confirmEmailChange(Request $request)
    {
        $request->validate([
            'new_email' => 'required|email|max:255|unique:users,email',
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();
        $newEmail = trim($request->input('new_email'));

        $oldVerified = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'email_old')
            ->whereNotNull('verified_at')
            ->exists();

        if (!$oldVerified) {
            return response()->json([
                'success' => false,
                'message' => '原信箱驗證狀態已失效，請重新開始流程。',
            ], 403);
        }

        $record = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'email_new')
            ->where('target_email', $newEmail)
            ->orderByDesc('id')
            ->first();

        if (!$record || Carbon::parse($record->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => '新信箱驗證碼不存在或已過期，請重新發送。',
            ], 422);
        }

        if ($record->code !== trim($request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => '新信箱驗證碼不正確！',
            ], 422);
        }

        $user->email = $newEmail;
        $user->save();

        DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->whereIn('purpose', ['email_old', 'email_new'])
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Email 已成功更新！',
            'user' => [
                'id' => $user->id,
                'identity' => $user->identity ?? '一般成員',
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    /**
     * 更改密碼 - 步驟 1：寄送驗證碼至目前帳號綁定的 Email
     */
    public function sendPasswordCode(Request $request)
    {
        $user = $request->user();

        $mailResult = $this->createAndSendCode(
            $user->id,
            'password_change',
            $user->email,
            '變更登入密碼',
            $user->name
        );

        if (!$mailResult['sent']) {
            return response()->json([
                'success' => false,
                'message' => '寄送密碼變更驗證碼失敗：' . ($mailResult['error'] ?? '請檢查寄信設定'),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => "驗證碼已寄送至您目前綁定的信箱（{$user->email}），請查收！",
        ], 200);
    }

    /**
     * 更改密碼 - 步驟 2：驗證驗證碼並更新新密碼
     */
    public function confirmPasswordChange(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'new_password.min' => '新密碼至少需 6 個字元！',
            'new_password.confirmed' => '兩次輸入的新密碼不一致！',
        ]);

        $user = $request->user();
        $record = DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'password_change')
            ->where('target_email', $user->email)
            ->orderByDesc('id')
            ->first();

        if (!$record || Carbon::parse($record->expires_at)->isPast()) {
            return response()->json([
                'success' => false,
                'message' => '驗證碼不存在或已過期，請重新發送。',
            ], 422);
        }

        if ($record->code !== trim($request->input('code'))) {
            return response()->json([
                'success' => false,
                'message' => '驗證碼不正確！',
            ], 422);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        DB::table('verification_codes')
            ->where('user_id', $user->id)
            ->where('purpose', 'password_change')
            ->delete();

        return response()->json([
            'success' => true,
            'message' => '密碼已成功變更！',
        ], 200);
    }

    /**
     * 更改使用者 ID (users.name)：輸入新 ID 與目前密碼驗證後完成變更
     */
    public function updateUsername(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'new_name' => 'required|string|max:255|unique:users,name,' . $user->id,
            'current_password' => 'required|string',
        ], [
            'new_name.required' => '請輸入想要變更的新使用者 ID！',
            'new_name.unique' => '此使用者 ID 已被他人使用，請換一個！',
        ]);

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => '目前密碼驗證失敗！',
            ], 422);
        }

        $user->name = trim($request->input('new_name'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => '使用者 ID 已成功變更！',
            'user' => [
                'id' => $user->id,
                'identity' => $user->identity ?? '一般成員',
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }
}
