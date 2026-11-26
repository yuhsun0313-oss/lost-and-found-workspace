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
     * 寄送「認領失敗／需重新驗證」通知信，並將失敗次數歸零以允許重新驗證。
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
     * 3. 新增失物/招領資料
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'category' => 'required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'nullable|string|max:255',
                'date' => 'nullable|date',
                'type' => 'nullable|string|max:50',
                'image' => 'nullable|string',
            ]);

            if (empty($validated['date'])) {
                $validated['date'] = Carbon::now()->toDateTimeString();
            }

            if (empty($validated['type'])) {
                $validated['type'] = 'lost';
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
                'message' => '資料驗證失敗',
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
     * 5. 失物認領防誤刪機制：VLM 視覺語言模型照片比對 + 失敗累計轉人工審核 + Email 通知
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
                'message' => '此物品目前已轉交管理員人工審核中（審核期限 3 天），請靜候 Email 通知結果。',
            ], 409);
        }

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
            $uploadedDataUri = str_starts_with($rawPhoto, 'data:image/')
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

        Log::info("VLM 比對準備 (Item #{$item->id})", [
            'model' => env('OLLAMA_VLM_MODEL', 'qwen2.5vl'),
            'has_original_image' => $originalBase64 !== null,
            'original_b64_len' => $originalBase64 ? strlen($originalBase64) : 0,
            'uploaded_b64_len' => strlen($uploadedBase64),
        ]);

        $vlmResult = $this->callOllamaVlm($item, $uploadedBase64, $originalBase64);

        if ($vlmResult === null) {
            return response()->json([
                'success' => false,
                'message' => 'VLM 視覺驗證服務暫時無法連線或逾時，請稍後再試（此次不計入失敗次數）。',
            ], 503);
        }

        $isMatch = $vlmResult['is_match'] && $vlmResult['confidence'] >= 60;
        $vlmReason = $vlmResult['reason'];

        if ($isMatch) {
            $item->claimant_email = $claimantEmail;
            $item->claim_proof_image = $uploadedDataUri;
            $item->vlm_last_reason = $vlmReason;
            $item->review_status = 'completed';
            $item->manual_review_deadline = null;
            $item->save();

            $mailSent = $this->sendClaimSuccessMail($claimantEmail, $item, 'VLM 視覺語言模型自動驗證');

            return response()->json([
                'success' => true,
                'verified' => true,
                'review_status' => 'completed',
                'mail_sent' => $mailSent,
                'confidence' => $vlmResult['confidence'],
                'vlm_reason' => $vlmReason,
                'message' => $mailSent
                    ? 'VLM 驗證成功！此失物已結案刪除，認領成功通知信已寄至您的信箱。'
                    : 'VLM 驗證成功！此失物已結案刪除（寄信設定尚未啟用或寄送失敗，已記錄於系統日誌）。',
            ], 200);
        }

        $sameClaimant = !empty($item->claimant_email)
            && strcasecmp($item->claimant_email, $claimantEmail) === 0;
        $newFailCount = ($sameClaimant ? (int) $item->vlm_fail_count : 0) + 1;

        $item->claimant_email = $claimantEmail;
        $item->claim_proof_image = $uploadedDataUri;
        $item->vlm_fail_count = $newFailCount;
        $item->vlm_last_reason = $vlmReason;

        if ($newFailCount >= 3) {
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
                'message' => '您對此物品的 VLM 驗證已累計失敗達 3 次，系統已自動轉交管理員進行「人工辨識審核」（限期 3 天），結果將以 Email 通知您。',
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

    private function extractRawBase64(?string $input): ?string
    {
        if (empty($input)) {
            return null;
        }
        $trimmed = trim($input);
        if (preg_match('/^data:image\/[a-zA-Z0-9.+-]+;base64,(.+)$/s', $trimmed, $matches)) {
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

        // 支援本機 / storage / public 相對路徑
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

    private function callOllamaVlm(Item $item, string $uploadedBase64, ?string $originalBase64): ?array
    {
        $ollamaUrl = rtrim(env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev'), '/');
        $vlmModel = env('OLLAMA_VLM_MODEL', 'qwen2.5vl');

        $images = $originalBase64 !== null
            ? [$originalBase64, $uploadedBase64]
            : [$uploadedBase64];

        $imageInstruction = $originalBase64 !== null
            ? '你會收到兩張圖片：第 1 張是資料庫登錄的「原失物照片」，第 2 張是認領人現場拍攝的「尋獲證明照片」。請以寬鬆合理的實務標準比對兩張照片是否為「同一件物品」（只要物品種類、主色系與主要外觀特徵一致，即使拍攝角度、距離、光線、背景或是否為同一張照片／截圖不同，也應視為相符）。'
            : '資料庫原失物未附帶圖片，你會收到 1 張認領人現場拍攝的「尋獲證明照片」。請根據以下失物文字資訊，判斷照片中的物品是否符合該失物描述。';

        $prompt = <<<PROMPT
你是校園失物招領系統的 VLM 視覺審核專家。
{$imageInstruction}

資料庫登錄失物資訊：
- 物品名稱：{$item->title}
- 物品分類：{$item->category}
- 地點：{$item->location}
- 補充說明：{$item->description}

請嚴格只回傳純 JSON 格式（不要任何 markdown 標記或額外文字）：
{
  "is_match": true 或 false,
  "confidence": 0 到 100 的整數,
  "reason": "使用繁體中文簡潔說明比對依據或不符合的原因"
}
PROMPT;

        try {
            $response = Http::withHeaders([
                'ngrok-skip-browser-warning' => 'true',
            ])->timeout(60)->post("{$ollamaUrl}/api/generate", [
                'model' => $vlmModel,
                'prompt' => $prompt,
                'images' => $images,
                'stream' => false,
                'format' => 'json',
                'options' => [
                    'temperature' => 0.1,
                    'num_predict' => 512,
                ],
            ]);

            if (!$response->successful()) {
                Log::warning('Ollama VLM HTTP 失敗: ' . $response->status() . ' ' . $response->body());
                return null;
            }

            $rawText = trim((string) $response->json('response', ''));
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

            return [
                'is_match' => filter_var($decoded['is_match'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'confidence' => max(0, min(100, (int) ($decoded['confidence'] ?? 0))),
                'reason' => trim((string) ($decoded['reason'] ?? 'VLM 已完成圖片比對。')),
            ];
        } catch (\Throwable $e) {
            Log::warning('Ollama VLM 呼叫例外: ' . $e->getMessage());
            return null;
        }
    }
}
