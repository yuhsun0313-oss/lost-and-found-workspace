<?php

namespace App\Http\Controllers;

use App\Mail\ClaimFailedMail;
use App\Mail\ClaimSuccessMail;
use App\Models\Item;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class ItemController extends Controller
{
    /**
     * 自動檢查並處理已超過 3 天人工審核期限的失物：
     * 僅寄信通知申請驗證者（claimant_email），並將失敗次數歸零以允許重新驗證。
     */
    public static function expireOverdueManualReviews(?int $onlyItemId = null): int
    {
        $query = Item::where('review_status', 'needs_manual_review')
            ->whereNotNull('manual_review_deadline')
            ->where('manual_review_deadline', '<', Carbon::now());

        if ($onlyItemId !== null) {
            $query->where('id', $onlyItemId);
        }

        $overdueItems = $query->get();
        $count = 0;

        foreach ($overdueItems as $item) {
            $recipientEmail = $item->claimant_email;
            if (!empty($recipientEmail)) {
                try {
                    Mail::to($recipientEmail)->send(
                        new ClaimFailedMail($item, '管理員人工審核已超過 3 天期限未處理，系統已重置您的 VLM 驗證次數。')
                    );
                } catch (\Throwable $e) {
                    Log::warning("逾期重置寄信失敗 (Item #{$item->id}): " . $e->getMessage());
                }
            }

            $item->vlm_fail_count = 0;
            $item->review_status = 'pending';
            $item->manual_review_deadline = null;
            $item->claim_proof_image = null;
            $item->vlm_last_reason = '人工審核超過 3 天期限，系統已自動重置驗證次數，可重新發起 VLM 驗證。';
            $item->save();
            $count++;
        }

        return $count;
    }

    /**
     * 1. 取得前台顯示中的失物清單（排除已結案 completed）
     */
    public function index()
    {
        try {
            if (!Schema::hasTable('items')) {
                return response()->json([], 200);
            }

            self::expireOverdueManualReviews();

            return Item::where(function ($q) {
                $q->whereNull('review_status')
                  ->orWhere('review_status', '!=', 'completed');
            })
            ->latest('id')
            ->get();
        } catch (\Exception $e) {
            Log::error('載入失物清單失敗: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    /**
     * 2. 取得單一物品詳細資料
     */
    public function show($id)
    {
        try {
            self::expireOverdueManualReviews((int) $id);

            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $item,
            ], 200);
        } catch (\Exception $e) {
            Log::error('取得單一物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3. 新增失物/招領資料（強制填寫聯絡人 Gmail 且強制上傳物品圖片）
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category' => 'required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'required|string|max:255',
                'publisher_email' => 'required|email|max:255',
                'date' => 'nullable|date',
                'type' => 'required|in:lost,found',
                'image' => 'required|string|min:20',
            ], [
                'publisher_email.required' => '請填寫聯絡人 Gmail 信箱！',
                'publisher_email.email' => '請填寫格式正確的聯絡人 Gmail 信箱！',
                'image.required' => '無論是招領或尋找，刊登時皆必須上傳物品照片！',
            ]);

            if (empty($validated['date'])) {
                $validated['date'] = Carbon::now()->toDateTimeString();
            }

            $validated['vlm_fail_count'] = 0;
            $validated['review_status'] = 'pending';

            $item = Item::create($validated);

            return response()->json([
                'success' => true,
                'message' => '物品新增成功',
                'data' => $item,
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?: '資料驗證失敗',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('新增物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 4. 編輯修改指定 ID 的物品資料
     */
    public function update(Request $request, $id)
    {
        try {
            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料，無法修改',
                ], 404);
            }

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'category' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'publisher_email' => 'sometimes|required|email|max:255',
                'date' => 'nullable|date',
                'type' => 'nullable|string|max:50',
                'image' => 'nullable|string',
            ]);

            $item->update($validated);

            return response()->json([
                'success' => true,
                'message' => '物品資料更新成功',
                'data' => $item,
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '資料驗證失敗',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('更新物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 5. 失物認領／尋獲驗證（VLM 照片比對 + 雙向 Email 通知 + 失敗累計 3 次轉人工不寄信）
     */
    public function verifyClaim(Request $request, $id)
    {
        $request->validate([
            'claimant_email' => 'required|email|max:255',
            'photo' => 'nullable',
        ]);

        $item = Item::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => '找不到該筆物品資料',
            ], 404);
        }

        // 先檢查該筆是否超過 3 天人工審核期限；若已逾期則自動歸零並發信通知
        self::expireOverdueManualReviews($item->id);
        $item->refresh();

        if ($item->review_status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => '此失物已完成認領並結案。',
            ], 400);
        }

        if ($item->review_status === 'needs_manual_review') {
            return response()->json([
                'success' => false,
                'needs_manual_review' => true,
                'review_status' => $item->review_status,
                'manual_review_deadline' => $item->manual_review_deadline?->toIso8601String(),
                'message' => '此物品目前已轉交管理員人工審核中（審核期限 3 天），請靜候管理員審核結果。',
            ], 409);
        }

        // 解析上傳現場照片（支援 multipart/form-data 檔案上傳 或 base64 Data URI / 純 Base64 字串）
        $uploadedDataUri = null;
        $uploadedBase64 = null;

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            if (!$file->isValid() || !str_starts_with((string) $file->getMimeType(), 'image/')) {
                return response()->json([
                    'success' => false,
                    'message' => '請上傳有效的圖片檔案（JPG、PNG 等）。',
                ], 422);
            }
            $bytes = file_get_contents($file->getRealPath());
            $uploadedBase64 = base64_encode($bytes);
            $uploadedDataUri = 'data:' . $file->getMimeType() . ';base64,' . $uploadedBase64;
        } elseif (is_string($request->input('photo')) && trim($request->input('photo')) !== '') {
            $rawPhoto = trim($request->input('photo'));
            $uploadedBase64 = $this->extractRawBase64($rawPhoto);
            $uploadedDataUri = preg_match('/^data:image\//i', $rawPhoto)
                ? $rawPhoto
                : ($uploadedBase64 ? 'data:image/jpeg;base64,' . $uploadedBase64 : $rawPhoto);
        }

        if (empty($uploadedBase64)) {
            return response()->json([
                'success' => false,
                'message' => '請強制上傳尋獲物品的現場照片以進行 VLM 比對！',
            ], 422);
        }

        $claimantEmail = trim($request->input('claimant_email'));
        $originalBase64 = $this->resolveImageToBase64($item->image);

        // 先檢查兩張圖片是否為完全相同或經相同檔案重新壓縮之影像
        $vlmResult = null;
        if ($originalBase64 !== null && $this->isSameOrNearIdenticalImage($originalBase64, $uploadedBase64)) {
            $vlmResult = [
                'is_match' => true,
                'confidence' => 100,
                'reason' => '比對確認上傳照片與原失物照片為同一張影像或特徵完全吻合。',
            ];
        } else {
            // 統一正規化為乾淨且適中大小的 JPEG Base64 後再傳入 Ollama VLM
            $normOriginal = $originalBase64 !== null ? $this->normalizeImageBase64ForVlm($originalBase64) : null;
            $normUploaded = $this->normalizeImageBase64ForVlm($uploadedBase64);

            Log::info("VLM 比對準備 (Item #{$item->id})", [
                'model' => env('OLLAMA_VLM_MODEL', 'qwen2.5vl'),
                'has_original_image' => $normOriginal !== null,
                'original_b64_len' => $normOriginal ? strlen($normOriginal) : 0,
                'uploaded_b64_len' => strlen($normUploaded),
            ]);

            $vlmResult = $this->callOllamaVlm($item, $normUploaded, $normOriginal);
        }

        if ($vlmResult === null) {
            return response()->json([
                'success' => false,
                'message' => 'VLM 視覺驗證服務暫時無法連線或逾時，請稍後再試（此次不計入失敗次數）。',
            ], 503);
        }

        $isMatch = $vlmResult['is_match'] && $vlmResult['confidence'] >= 50;
        $vlmReason = $vlmResult['reason'];

        if ($isMatch) {
            $item->claimant_email = $claimantEmail;
            $item->claim_proof_image = $uploadedDataUri;
            $item->vlm_last_reason = $vlmReason;
            $item->review_status = 'completed';
            $item->manual_review_deadline = null;
            $item->save();

            // 雙向情境通知邏輯：
            // 情境 A (type === 'found') 與 情境 B (type === 'lost')：VLM 自動驗證成功皆寄信通知「原刊登者 (publisher_email)」
            $mailSent = false;
            if (!empty($item->publisher_email)) {
                $mailSent = $this->sendClaimSuccessMail(
                    $item->publisher_email,
                    $item,
                    'VLM 視覺語言模型自動驗證',
                    'publisher',
                    $claimantEmail
                );
            }

            return response()->json([
                'success' => true,
                'verified' => true,
                'review_status' => 'completed',
                'mail_sent' => $mailSent,
                'confidence' => $vlmResult['confidence'],
                'vlm_reason' => $vlmReason,
                'message' => '已刪除該物品，已完成認領結案！' . ($mailSent ? '系統已寄出通知信給原刊登者以便聯繫面交。' : ''),
            ], 200);
        }

        // VLM 驗證失敗：累計該使用者對此物品的失敗次數
        $sameClaimant = !empty($item->claimant_email)
            && strcasecmp($item->claimant_email, $claimantEmail) === 0;
        $newFailCount = ($sameClaimant ? (int) $item->vlm_fail_count : 0) + 1;

        $item->claimant_email = $claimantEmail;
        $item->claim_proof_image = $uploadedDataUri;
        $item->vlm_fail_count = $newFailCount;
        $item->vlm_last_reason = $vlmReason;

        if ($newFailCount >= 3) {
            // 連續失敗達 3 次：自動轉交管理員人工審核，一律「不寄送任何 Email」
            $item->review_status = 'needs_manual_review';
            $item->manual_review_deadline = Carbon::now()->addDays(3);
            $item->save();

            return response()->json([
                'success' => true,
                'verified' => false,
                'vlm_fail_count' => $newFailCount,
                'remaining_attempts' => 0,
                'needs_manual_review' => true,
                'review_status' => 'needs_manual_review',
                'manual_review_deadline' => $item->manual_review_deadline->toIso8601String(),
                'vlm_reason' => $vlmReason,
                'message' => '您對此物品的 VLM 驗證已累計失敗達 3 次，系統已自動轉交管理員進行「人工辨識審核」（限期 3 天），後續將由管理員審核後通知。',
            ], 200);
        }

        $item->review_status = 'pending';
        $item->save();

        $remaining = max(0, 3 - $newFailCount);

        return response()->json([
            'success' => true,
            'verified' => false,
            'vlm_fail_count' => $newFailCount,
            'remaining_attempts' => $remaining,
            'needs_manual_review' => false,
            'review_status' => 'pending',
            'vlm_reason' => $vlmReason,
            'message' => "照片與原失物特徵不符（目前失敗 {$newFailCount}/3 次，還剩 {$remaining} 次機會）。原因：{$vlmReason}",
        ], 200);
    }

    /**
     * 6. 管理員後台：取得所有失物（強制置頂 needs_manual_review 並自動處理逾期）
     */
    public function adminIndex()
    {
        try {
            self::expireOverdueManualReviews();

            $items = Item::orderByRaw("
                CASE
                    WHEN review_status = 'needs_manual_review' THEN 0
                    WHEN review_status = 'pending' OR review_status IS NULL THEN 1
                    ELSE 2
                END ASC
            ")
            ->orderBy('manual_review_deadline', 'asc')
            ->orderBy('id', 'desc')
            ->get();

            return response()->json([
                'success' => true,
                'items' => $items,
            ], 200);
        } catch (\Exception $e) {
            Log::error('管理後台載入失物失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '載入管理清單失敗：' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 7. 管理員人工審核通過：依雙向情境寄送 Email 並結案
     * - 情境 A (found): 寄給原刊登者 (publisher_email)
     * - 情境 B (lost):  寄給原刊登者 (publisher_email) 且 寄給回報找到的人 (claimant_email)
     */
    public function adminApprove($id)
    {
        $item = Item::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => '找不到該筆失物資料',
            ], 404);
        }

        $publisherEmail = $item->publisher_email;
        $claimantEmail = $item->claimant_email;

        $item->review_status = 'completed';
        $item->manual_review_deadline = null;
        $item->save();

        $publisherMailSent = false;
        $claimantMailSent = false;

        if (!empty($publisherEmail)) {
            $publisherMailSent = $this->sendClaimSuccessMail(
                $publisherEmail,
                $item,
                '管理員人工審核',
                'publisher',
                $claimantEmail
            );
        }

        // 情境 B（正在尋找）：人工審核通過亦須通知「回報找到的人（驗證申請者）」
        if ($item->type === 'lost' && !empty($claimantEmail)) {
            $claimantMailSent = $this->sendClaimSuccessMail(
                $claimantEmail,
                $item,
                '管理員人工審核',
                'claimant',
                $publisherEmail
            );
        }

        $anyMailSent = $publisherMailSent || $claimantMailSent;

        return response()->json([
            'success' => true,
            'mail_sent' => $anyMailSent,
            'publisher_mail_sent' => $publisherMailSent,
            'claimant_mail_sent' => $claimantMailSent,
            'message' => '已通過人工審核並完成結案' . ($anyMailSent ? '，已依情境寄出通知信！' : '。'),
            'data' => $item,
        ], 200);
    }

    /**
     * 8. 管理員人工審核失敗（退回）：
     * - 情境 A & B 皆只寄信通知「驗證申請者 (claimant_email)」，原刊登者不會收到信
     * - 並將失敗次數歸零以允許重新驗證
     */
    public function adminReject(Request $request, $id)
    {
        $item = Item::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => '找不到該筆失物資料',
            ], 404);
        }

        $reason = trim((string) $request->input('reason', '')) ?: '管理員比對現場照片與原失物特徵不符，請重新上傳清晰照片。';
        $recipient = $item->claimant_email;

        $mailSent = false;
        if (!empty($recipient)) {
            try {
                Mail::to($recipient)->send(new ClaimFailedMail($item, $reason));
                $mailSent = true;
            } catch (\Throwable $e) {
                Log::warning("人工審核失敗寄信異常 (Item #{$item->id}): " . $e->getMessage());
            }
        }

        $item->vlm_fail_count = 0;
        $item->review_status = 'pending';
        $item->manual_review_deadline = null;
        $item->claim_proof_image = null;
        $item->vlm_last_reason = $reason;
        $item->save();

        return response()->json([
            'success' => true,
            'mail_sent' => $mailSent,
            'message' => '已退回人工審核，VLM 失敗次數已歸零（可重新驗證最多 3 次）' . ($mailSent ? '，已寄出通知信給申請驗證者！' : '。'),
            'data' => $item,
        ], 200);
    }

    /**
     * 9. 管理員永久刪除資料
     */
    public function destroy($id)
    {
        try {
            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料',
                ], 404);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => '物品刪除成功',
            ], 200);
        } catch (\Exception $e) {
            Log::error('刪除物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage(),
            ], 500);
        }
    }

    private function sendClaimSuccessMail(
        string $email,
        Item $item,
        string $verifiedBy,
        string $recipientRole = 'publisher',
        ?string $partnerEmail = null
    ): bool {
        try {
            Mail::to($email)->send(new ClaimSuccessMail($item, $verifiedBy, $recipientRole, $partnerEmail));
            return true;
        } catch (\Throwable $e) {
            Log::warning("認領成功寄信失敗 (Item #{$item->id}, Role {$recipientRole}): " . $e->getMessage());
            return false;
        }
    }

    private function extractRawBase64(?string $input): ?string
    {
        if (empty($input)) {
            return null;
        }
        $trimmed = trim($input);
        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+(?:;[^;,]+)*;base64,(.+)$/is', $trimmed, $matches)) {
            return preg_replace('/\s+/', '', $matches[1]);
        }
        $cleaned = preg_replace('/\s+/', '', $trimmed);
        if (strlen($cleaned) > 100 && base64_decode($cleaned, true) !== false) {
            return $cleaned;
        }
        return null;
    }

    private function resolveImageToBase64(?string $imageField): ?string
    {
        if (empty($imageField)) {
            return null;
        }

        $trimmed = trim($imageField);

        $fromDataUri = $this->extractRawBase64($trimmed);
        if ($fromDataUri !== null) {
            return $fromDataUri;
        }

        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            try {
                $resp = Http::timeout(10)->get($trimmed);
                if ($resp->successful()) {
                    return base64_encode($resp->body());
                }
            } catch (\Throwable $e) {
                Log::warning('下載原失物圖片轉 Base64 失敗: ' . $e->getMessage());
            }
        }

        $relativePath = ltrim(preg_replace('#^/?storage/#', '', $trimmed), '/');
        $candidatePaths = [
            public_path(ltrim($trimmed, '/')),
            storage_path('app/public/' . $relativePath),
            storage_path('app/' . $relativePath),
            base_path($relativePath),
        ];

        foreach ($candidatePaths as $path) {
            if (is_file($path) && is_readable($path)) {
                $bytes = @file_get_contents($path);
                if ($bytes !== false && strlen($bytes) > 0) {
                    return base64_encode($bytes);
                }
            }
        }

        return null;
    }

    /**
     * 檢查兩張圖片是否為完全相同位元組，或同一張圖片經前端 Canvas 重壓縮後的極高相似度複本
     */
    private function isSameOrNearIdenticalImage(string $origBase64, string $upBase64): bool
    {
        $origBytes = base64_decode($origBase64, true);
        $upBytes = base64_decode($upBase64, true);
        if ($origBytes === false || $upBytes === false) {
            return false;
        }

        if (hash_equals(hash('sha256', $origBytes), hash('sha256', $upBytes))) {
            return true;
        }

        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $imgA = @imagecreatefromstring($origBytes);
        $imgB = @imagecreatefromstring($upBytes);
        if (!$imgA || !$imgB) {
            if ($imgA) imagedestroy($imgA);
            if ($imgB) imagedestroy($imgB);
            return false;
        }

        $wA = imagesx($imgA);
        $hA = imagesy($imgA);
        $wB = imagesx($imgB);
        $hB = imagesy($imgB);

        $ratioA = $hA > 0 ? $wA / $hA : 1;
        $ratioB = $hB > 0 ? $wB / $hB : 1;

        if (abs($ratioA - $ratioB) > 0.08) {
            imagedestroy($imgA);
            imagedestroy($imgB);
            return false;
        }

        $size = 16;
        $thumbA = imagecreatetruecolor($size, $size);
        $thumbB = imagecreatetruecolor($size, $size);
        imagecopyresampled($thumbA, $imgA, 0, 0, 0, 0, $size, $size, $wA, $hA);
        imagecopyresampled($thumbB, $imgB, 0, 0, 0, 0, $size, $size, $wB, $hB);

        $totalDiff = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $cA = imagecolorat($thumbA, $x, $y);
                $cB = imagecolorat($thumbB, $x, $y);
                $totalDiff += abs((($cA >> 16) & 0xFF) - (($cB >> 16) & 0xFF));
                $totalDiff += abs((($cA >> 8) & 0xFF) - (($cB >> 8) & 0xFF));
                $totalDiff += abs(($cA & 0xFF) - ($cB & 0xFF));
            }
        }

        imagedestroy($imgA);
        imagedestroy($imgB);
        imagedestroy($thumbA);
        imagedestroy($thumbB);

        $mae = $totalDiff / ($size * $size * 3);
        return $mae <= 10.0;
    }

    /**
     * 使用 GD 將圖片統一轉換為適中尺寸（最長邊 768px）的乾淨 JPEG Base64，
     * 避免 PNG 透明通道、WebP 或過大圖檔造成 VLM 模型推論異常或逾時
     */
    private function normalizeImageBase64ForVlm(string $rawBase64): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $rawBase64;
        }

        $bytes = base64_decode($rawBase64, true);
        if ($bytes === false) {
            return $rawBase64;
        }

        $src = @imagecreatefromstring($bytes);
        if (!$src) {
            return $rawBase64;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $maxDim = 768;
        $scale = max($w, $h) > $maxDim ? ($maxDim / max($w, $h)) : 1.0;
        $newW = max(1, (int) round($w * $scale));
        $newH = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);

        ob_start();
        imagejpeg($dst, null, 85);
        $jpegBytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        return !empty($jpegBytes) ? base64_encode($jpegBytes) : $rawBase64;
    }

    private function callOllamaVlm(Item $item, string $uploadedBase64, ?string $originalBase64): ?array
    {
        $ollamaUrl = rtrim(env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev'), '/');
        $vlmModel = env('OLLAMA_VLM_MODEL', 'qwen2.5vl');

        $images = $originalBase64 !== null
            ? [$originalBase64, $uploadedBase64]
            : [$uploadedBase64];

        $imageInstruction = $originalBase64 !== null
            ? '你會收到兩張圖片：第 1 張是資料庫登錄的「原失物照片」，第 2 張是認領／尋獲人提交的「現場照片」。請比對兩張圖片中的核心物品是否為同一類或同一件物品。若兩張照片為同一張圖、翻拍截圖，或物品的品項種類、主色調與關鍵外觀特徵相符（即使角度、距離、光線或背景不同），請務必判定為 is_match: true 並給予 75~100 的整數 confidence！僅在兩張圖明顯為完全不同種類或截然不同顏色的物品時，才判定為 is_match: false。'
            : '資料庫原失物未附帶圖片，你會收到 1 張現場照片。請根據以下失物文字資訊，判斷照片中的物品是否符合該失物描述。';

        $prompt = <<<PROMPT
你是校園失物招領系統的 VLM 視覺審核專家。
{$imageInstruction}

資料庫登錄失物資訊：
- 物品名稱：{$item->title}
- 物品分類：{$item->category}
- 地點：{$item->location}
- 補充說明：{$item->description}

請嚴格只回傳純 JSON 格式（不要任何 markdown 標記或額外文字，confidence 必須是 0 到 100 的整數）：
{
  "is_match": true,
  "confidence": 90,
  "reason": "使用繁體中文簡潔說明比對依據或不符合的原因"
}
PROMPT;

        try {
            // 優先使用 Ollama /api/chat（對 qwen2.5vl / llama3.2-vision / llava 多圖輸入相容性最佳）
            $response = Http::withHeaders([
                'ngrok-skip-browser-warning' => 'true',
            ])->timeout(90)->post("{$ollamaUrl}/api/chat", [
                'model' => $vlmModel,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                        'images' => $images,
                    ],
                ],
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0.0,
                    'num_predict' => 512,
                ],
            ]);

            $rawText = '';
            if ($response->successful()) {
                $rawText = trim((string) ($response->json('message.content') ?? $response->json('response', '')));
            } else {
                // 若端點只支援 /api/generate，自動降級改打 /api/generate
                $genResponse = Http::withHeaders([
                    'ngrok-skip-browser-warning' => 'true',
                ])->timeout(90)->post("{$ollamaUrl}/api/generate", [
                    'model' => $vlmModel,
                    'prompt' => $prompt,
                    'images' => $images,
                    'stream' => false,
                    'format' => 'json',
                    'options' => [
                        'temperature' => 0.0,
                        'num_predict' => 512,
                    ],
                ]);

                if (!$genResponse->successful()) {
                    Log::warning('Ollama VLM HTTP 失敗: ' . $genResponse->status() . ' ' . $genResponse->body());
                    return null;
                }
                $rawText = trim((string) $genResponse->json('response', ''));
            }

            Log::info("Ollama VLM 原始回覆 (Item #{$item->id}): " . $rawText);

            $cleanJson = trim(str_replace(['```json', '```', '`'], '', $rawText));
            $firstBrace = strpos($cleanJson, '{');
            $lastBrace = strrpos($cleanJson, '}');

            if ($firstBrace === false || $lastBrace === false) {
                Log::warning('Ollama VLM 回傳非 JSON: ' . $rawText);
                return null;
            }

            $decoded = json_decode(substr($cleanJson, $firstBrace, $lastBrace - $firstBrace + 1), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return null;
            }

            $isMatch = filter_var($decoded['is_match'] ?? false, FILTER_VALIDATE_BOOLEAN);

            // 修正 0.0~1.0 浮點數（例如 0.95 被強制轉 int 成 0）或百分比字串（"90%"）的解析異常
            $rawConf = $decoded['confidence'] ?? ($isMatch ? 80 : 0);
            if (is_string($rawConf)) {
                $rawConf = str_replace(['%', '分', ' '], '', $rawConf);
            }
            $confFloat = is_numeric($rawConf) ? (float) $rawConf : ($isMatch ? 80.0 : 0.0);
            if ($confFloat > 0.0 && $confFloat <= 1.0) {
                $confFloat *= 100.0;
            }
            $confidence = max(0, min(100, (int) round($confFloat)));
            if ($isMatch && $confidence === 0) {
                $confidence = 80;
            }

            return [
                'is_match' => $isMatch,
                'confidence' => $confidence,
                'reason' => trim((string) ($decoded['reason'] ?? 'VLM 已完成圖片比對。')),
            ];
        } catch (\Throwable $e) {
            Log::warning('Ollama VLM 呼叫例外: ' . $e->getMessage());
            return null;
        }
    }
}
