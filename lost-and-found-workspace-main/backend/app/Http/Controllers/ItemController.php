<?php

namespace App\Http\Controllers;

use App\Mail\ClaimFailedMail;
use App\Mail\ClaimSuccessMail;
use App\Models\Item;
use App\Models\User;
use App\Services\MailDispatcher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ItemController extends Controller
{
    /**
     * 自動檢查並處理已超過 3 天人工審核期限的失物：
     * 寄信通知申請驗證者（claimant_email），並重置失敗次數以允許重新驗證。
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
                MailDispatcher::send(
                    $recipientEmail,
                    new ClaimFailedMail($item, '管理員人工審核已超過 3 天期限未處理，系統已重置您的驗證次數，請重新上傳圖片並進行驗證。')
                );
            }

            if (Schema::hasTable('item_claim_attempts')) {
                DB::table('item_claim_attempts')
                    ->where('item_id', $item->id)
                    ->where('status', 'needs_manual_review')
                    ->update([
                        'attempt_count' => 0,
                        'status' => 'rejected',
                        'updated_at' => Carbon::now(),
                    ]);
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
     * 自動清除已結案（completed）且超過 3 個月保留期限的物品資料
     */
    public static function purgeExpiredCompletedItems(): int
    {
        if (!Schema::hasTable('items')) {
            return 0;
        }

        $now = Carbon::now();
        $threeMonthsAgo = $now->copy()->subMonths(3);

        $query = Item::where('review_status', 'completed')
            ->where(function ($q) use ($now, $threeMonthsAgo) {
                if (Schema::hasColumn('items', 'completed_delete_deadline')) {
                    $q->where(function ($inner) use ($now) {
                        $inner->whereNotNull('completed_delete_deadline')
                              ->where('completed_delete_deadline', '<', $now);
                    })->orWhere(function ($inner) use ($threeMonthsAgo) {
                        $inner->whereNull('completed_delete_deadline')
                              ->where('updated_at', '<', $threeMonthsAgo);
                    });
                } else {
                    $q->where('updated_at', '<', $threeMonthsAgo);
                }
            });

        $expiredItems = $query->get();
        $count = 0;

        foreach ($expiredItems as $item) {
            if (Schema::hasTable('item_claim_attempts')) {
                DB::table('item_claim_attempts')->where('item_id', $item->id)->delete();
            }
            $item->delete();
            $count++;
        }

        return $count;
    }

    /**
     * 判斷目前使用者是否為該物品的刊登者或管理員
     */
    private function isOwnerOrAdmin(?User $user, Item $item): bool
    {
        if (!$user) {
            return false;
        }
        if ($user->identity === '管理員') {
            return true;
        }
        if ($item->user_id !== null && (int) $item->user_id === (int) $user->id) {
            return true;
        }
        if ($item->user_id === null && !empty($item->publisher_email) && strcasecmp($item->publisher_email, $user->email) === 0) {
            return true;
        }
        return false;
    }

    /**
     * 1. 取得前台顯示中的失物清單（排除已結案 completed，並附帶目前帳號的已嘗試驗證次數）
     */
    public function index(Request $request)
    {
        try {
            if (!Schema::hasTable('items')) {
                return response()->json([], 200);
            }

            self::expireOverdueManualReviews();
            self::purgeExpiredCompletedItems();

            $items = Item::where(function ($q) {
                $q->whereNull('review_status')
                  ->orWhere('review_status', '!=', 'completed');
            })
            ->latest('id')
            ->get();

            $user = $request->user('sanctum');
            if ($user && Schema::hasTable('item_claim_attempts')) {
                $attemptsMap = DB::table('item_claim_attempts')
                    ->where('user_id', $user->id)
                    ->pluck('attempt_count', 'item_id');

                $items->each(function ($item) use ($attemptsMap) {
                    $item->my_attempt_count = (int) ($attemptsMap[$item->id] ?? 0);
                });
            }

            return $items;
        } catch (\Exception $e) {
            Log::error('載入失物清單失敗: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    /**
     * 2. 取得單一物品詳細資料
     */
    public function show(Request $request, $id)
    {
        try {
            self::expireOverdueManualReviews((int) $id);
            self::purgeExpiredCompletedItems();

            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料',
                ], 404);
            }

            $user = $request->user('sanctum');
            if ($user && Schema::hasTable('item_claim_attempts')) {
                $attempt = DB::table('item_claim_attempts')
                    ->where('item_id', $item->id)
                    ->where('user_id', $user->id)
                    ->first();
                $item->my_attempt_count = $attempt ? (int) $attempt->attempt_count : 0;
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
            $user = $request->user('sanctum');

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

            $validated['user_id'] = $user?->id;
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
     * 4. 刊登者／管理員編輯修改指定 ID 的刊登中物品資料
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料，無法修改',
                ], 404);
            }

            if (!$this->isOwnerOrAdmin($user, $item)) {
                return response()->json([
                    'success' => false,
                    'message' => '權限不足，僅限原刊登者或管理員可編輯此物品。',
                ], 403);
            }

            if ($item->review_status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => '已結案物品不可編輯。',
                ], 400);
            }

            $validated = $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'category' => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'location' => 'sometimes|required|string|max:255',
                'publisher_email' => 'sometimes|required|email|max:255',
                'date' => 'nullable|date',
                'type' => 'sometimes|required|in:lost,found',
                'image' => 'sometimes|required|string|min:20',
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
                'message' => collect($e->errors())->flatten()->first() ?: '資料驗證失敗',
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
     * 5. 失物認領／尋獲驗證：
     * - 每個帳號對單一物品最多上傳驗證 3 次
     * - 累計達 3 次自動轉人工審核，不寄送 Email
     * - 驗證成功標記 completed 並設定 3 個月後自動刪除期限，依收件人邏輯寄出通知信
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
        self::purgeExpiredCompletedItems();
        $item = Item::find($id);
        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => '此失物已超過保留期限並自動清除。',
            ], 404);
        }

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

        $claimantEmail = trim($request->input('claimant_email'));
        $authUser = $request->user('sanctum');

        // 檢查「單一帳號對此物品最多 3 次」限制
        $attemptRecord = null;
        if (Schema::hasTable('item_claim_attempts')) {
            if ($authUser) {
                $attemptRecord = DB::table('item_claim_attempts')
                    ->where('item_id', $item->id)
                    ->where('user_id', $authUser->id)
                    ->first();
            } else {
                $attemptRecord = DB::table('item_claim_attempts')
                    ->where('item_id', $item->id)
                    ->whereNull('user_id')
                    ->whereRaw('LOWER(claimant_email) = ?', [strtolower($claimantEmail)])
                    ->first();
            }

            if ($attemptRecord && (int) $attemptRecord->attempt_count >= 3) {
                return response()->json([
                    'success' => false,
                    'needs_manual_review' => true,
                    'vlm_fail_count' => (int) $attemptRecord->attempt_count,
                    'remaining_attempts' => 0,
                    'message' => '您對此物品的上傳驗證次數已達上限（3/3 次），請等待管理員人工審核結果。',
                ], 409);
            }
        }

        // 解析上傳現場照片
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

        $originalBase64 = $this->resolveImageToBase64($item->image);

        // 先在程式層檢測上傳圖是否為全黑、全白、純色等無效畫面，避免浪費 VLM 推論或誤判
        if ($this->isBlankOrPitchDarkImage($uploadedBase64)) {
            $vlmResult = [
                'image1_observation' => '',
                'image2_observation' => '畫面為全黑、全白、純色或極度過暗，無法辨識任何明確物體。',
                'is_match' => false,
                'confidence' => 0,
                'reason' => '現場驗證照片為全黑、純色或過暗無效畫面，無法辨識出任何物品。',
            ];
        } elseif ($originalBase64 !== null && $this->isSameOrNearIdenticalImage($originalBase64, $uploadedBase64)) {
            $vlmResult = [
                'image1_observation' => '與第 2 張上傳圖片為同一張有效影像。',
                'image2_observation' => '與第 1 張原失物照片影像特徵完全一致。',
                'is_match' => true,
                'confidence' => 100,
                'reason' => '比對確認上傳照片與原失物照片為同一張影像或特徵完全吻合。',
            ];
        } else {
            $normOriginal = $originalBase64 !== null ? $this->normalizeImageBase64ForVlm($originalBase64) : null;
            $normUploaded = $this->normalizeImageBase64ForVlm($uploadedBase64);
            $vlmResult = $this->callOllamaVlm($item, $normUploaded, $normOriginal);
        }

        if ($vlmResult === null) {
            return response()->json([
                'success' => false,
                'message' => 'VLM 視覺驗證服務暫時無法連線或逾時，請稍後再試（此次不計入失敗次數）。',
            ], 503);
        }

        $image2Observation = trim((string) ($vlmResult['image2_observation'] ?? ''));
        $hasInvalidObservation = $this->hasInvalidObservationKeywords($image2Observation);

        $isMatch = $vlmResult['is_match']
            && $vlmResult['confidence'] >= 70
            && !$hasInvalidObservation;

        $vlmReason = $vlmResult['reason'];
        if ($vlmResult['is_match'] && $hasInvalidObservation) {
            $isMatch = false;
            $vlmReason = "系統防呆攔截：現場照片無效或無法辨識（{$image2Observation}），判定為不符合。";
            Log::warning("VLM 幻覺防呆攔截 (Item #{$item->id})", [
                'raw_is_match' => $vlmResult['is_match'],
                'confidence' => $vlmResult['confidence'],
                'image2_observation' => $image2Observation,
            ]);
        }

        if ($isMatch) {
            $now = Carbon::now();
            $item->claimant_email = $claimantEmail;
            $item->claim_proof_image = $uploadedDataUri;
            $item->vlm_last_reason = $vlmReason;
            $item->review_status = 'completed';
            $item->manual_review_deadline = null;
            $item->completed_at = $now;
            $item->completed_delete_deadline = $now->copy()->addMonths(3);
            $item->save();

            if (Schema::hasTable('item_claim_attempts')) {
                $this->upsertAttemptRecord($attemptRecord?->id, [
                    'item_id' => $item->id,
                    'user_id' => $authUser?->id,
                    'claimant_email' => $claimantEmail,
                    'attempt_count' => $attemptRecord ? (int) $attemptRecord->attempt_count : 0,
                    'status' => 'approved',
                    'last_proof_image' => $uploadedDataUri,
                    'last_reason' => $vlmReason,
                ]);
            }

            // 寄信通知邏輯：寄送給原刊登者 (publisher_email)；若舊資料無 publisher_email 則降級通知 claimant_email
            $mailResults = [];
            if (!empty($item->publisher_email)) {
                $mailResults[] = MailDispatcher::send(
                    $item->publisher_email,
                    new ClaimSuccessMail($item, 'VLM 視覺語言模型自動驗證', 'publisher', $claimantEmail)
                );
            } elseif (!empty($claimantEmail)) {
                $mailResults[] = MailDispatcher::send(
                    $claimantEmail,
                    new ClaimSuccessMail($item, 'VLM 視覺語言模型自動驗證', 'claimant', null)
                );
            }

            $mailSent = collect($mailResults)->contains(fn($r) => $r['sent'] === true);
            $mailErrors = collect($mailResults)->pluck('error')->filter()->values()->all();

            return response()->json([
                'success' => true,
                'verified' => true,
                'review_status' => 'completed',
                'mail_sent' => $mailSent,
                'mail_error' => !empty($mailErrors) ? implode('；', $mailErrors) : null,
                'confidence' => $vlmResult['confidence'],
                'vlm_reason' => $vlmReason,
                'message' => '已刪除該物品，已完成認領結案！'
                    . ($mailSent ? '系統已寄出通知信以便聯繫面交。' : (!empty($mailErrors) ? '（寄信異常：' . $mailErrors[0] . '）' : '')),
            ], 200);
        }

        // VLM 驗證失敗：累加該帳號（或非登入訪客 Email）對此物品的失敗次數
        $currentCount = $attemptRecord ? (int) $attemptRecord->attempt_count : 0;
        $newFailCount = $currentCount + 1;
        $escalated = $newFailCount >= 3;

        if (Schema::hasTable('item_claim_attempts')) {
            $this->upsertAttemptRecord($attemptRecord?->id, [
                'item_id' => $item->id,
                'user_id' => $authUser?->id,
                'claimant_email' => $claimantEmail,
                'attempt_count' => $newFailCount,
                'status' => $escalated ? 'needs_manual_review' : 'active',
                'last_proof_image' => $uploadedDataUri,
                'last_reason' => $vlmReason,
            ]);
        }

        $item->claimant_email = $claimantEmail;
        $item->claim_proof_image = $uploadedDataUri;
        $item->vlm_fail_count = $newFailCount;
        $item->vlm_last_reason = $vlmReason;

        if ($escalated) {
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
            'message' => "照片與原失物特徵不符（此帳號目前失敗 {$newFailCount}/3 次，還剩 {$remaining} 次機會）。原因：{$vlmReason}",
        ], 200);
    }

    /**
     * 6. 管理員後台：取得所有失物（強制置頂 needs_manual_review 並自動處理逾期與 3 個月到期已結案資料）
     */
    public function adminIndex()
    {
        try {
            self::expireOverdueManualReviews();
            self::purgeExpiredCompletedItems();

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
     * 7. 管理員人工審核通過：標記已結案（保留 3 個月後自動刪除）並依情境寄信
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

        $publisherEmail = trim((string) $item->publisher_email);
        $claimantEmail = trim((string) $item->claimant_email);
        $now = Carbon::now();

        $item->review_status = 'completed';
        $item->manual_review_deadline = null;
        $item->completed_at = $now;
        $item->completed_delete_deadline = $now->copy()->addMonths(3);
        $item->save();

        if (Schema::hasTable('item_claim_attempts')) {
            DB::table('item_claim_attempts')
                ->where('item_id', $item->id)
                ->where('status', 'needs_manual_review')
                ->update(['status' => 'approved', 'updated_at' => $now]);
        }

        $mailResults = [];

        if ($publisherEmail !== '') {
            $mailResults[] = MailDispatcher::send(
                $publisherEmail,
                new ClaimSuccessMail($item, '管理員人工審核', 'publisher', $claimantEmail)
            );
        }

        // 若為「正在尋找」且有回報者信箱，或舊資料未填 publisher_email 時，寄信通知申請驗證者
        if ($claimantEmail !== '' && ($item->type === 'lost' || $publisherEmail === '') && strcasecmp($claimantEmail, $publisherEmail) !== 0) {
            $mailResults[] = MailDispatcher::send(
                $claimantEmail,
                new ClaimSuccessMail($item, '管理員人工審核', 'claimant', $publisherEmail)
            );
        }

        $anyMailSent = collect($mailResults)->contains(fn($r) => $r['sent'] === true);
        $mailErrors = collect($mailResults)->pluck('error')->filter()->values()->all();

        $msg = '已通過人工審核並完成結案（資料將保留 3 個月後自動刪除）';
        if ($anyMailSent && empty($mailErrors)) {
            $msg .= '，已寄出通知信！';
        } elseif (!empty($mailErrors)) {
            $msg .= '；但部分或全部通知信寄送失敗：' . $mailErrors[0];
        } else {
            $msg .= '。';
        }

        return response()->json([
            'success' => true,
            'mail_sent' => $anyMailSent,
            'mail_error' => !empty($mailErrors) ? implode('；', $mailErrors) : null,
            'message' => $msg,
            'data' => $item,
        ], 200);
    }

    /**
     * 8. 管理員人工審核駁回（退回）：
     * - 寄信通知申請驗證者（claimant_email）提示已被人工駁回，請重新上傳圖片並進行驗證
     * - 重置該帳號對此物品的驗證次數為 0，可重新獲得 3 次驗證機會
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

        $reason = trim((string) $request->input('reason', '')) ?: '您的申請已被管理員人工審核駁回，請重新上傳清晰的物品圖片並進行驗證。';
        $recipient = trim((string) $item->claimant_email);

        $mailResult = ['sent' => false, 'error' => null];
        if ($recipient !== '') {
            $mailResult = MailDispatcher::send($recipient, new ClaimFailedMail($item, $reason));
        } else {
            $mailResult['error'] = '找不到申請驗證者的聯絡信箱，無法寄出駁回通知信。';
        }

        if (Schema::hasTable('item_claim_attempts')) {
            DB::table('item_claim_attempts')
                ->where('item_id', $item->id)
                ->where(function ($q) use ($recipient) {
                    $q->where('status', 'needs_manual_review')
                      ->orWhereRaw('LOWER(claimant_email) = ?', [strtolower($recipient)]);
                })
                ->update([
                    'attempt_count' => 0,
                    'status' => 'rejected',
                    'last_proof_image' => null,
                    'last_reason' => $reason,
                    'updated_at' => Carbon::now(),
                ]);
        }

        $item->vlm_fail_count = 0;
        $item->review_status = 'pending';
        $item->manual_review_deadline = null;
        $item->claim_proof_image = null;
        $item->vlm_last_reason = $reason;
        $item->save();

        $msg = '已執行人工審核駁回，該帳號對此物品的驗證次數已重置（可重新獲得 3 次機會）';
        if ($mailResult['sent']) {
            $msg .= "，已寄出駁回通知信至 {$recipient}！";
        } elseif (!empty($mailResult['error'])) {
            $msg .= '；但通知信寄送失敗：' . $mailResult['error'];
        }

        return response()->json([
            'success' => true,
            'mail_sent' => $mailResult['sent'],
            'mail_error' => $mailResult['error'],
            'message' => $msg,
            'data' => $item,
        ], 200);
    }

    /**
     * 9. 刊登者自行刪除（刊登中）或管理員永久刪除資料
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            $item = Item::find($id);
            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料',
                ], 404);
            }

            if (!$this->isOwnerOrAdmin($user, $item)) {
                return response()->json([
                    'success' => false,
                    'message' => '權限不足，僅限原刊登者或管理員可刪除此物品。',
                ], 403);
            }

            if ($user->identity !== '管理員' && $item->review_status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => '已結案物品僅限管理員永久清除。',
                ], 403);
            }

            if (Schema::hasTable('item_claim_attempts')) {
                DB::table('item_claim_attempts')->where('item_id', $item->id)->delete();
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

    private function upsertAttemptRecord(?int $existingId, array $data): void
    {
        $now = Carbon::now();
        if ($existingId !== null) {
            DB::table('item_claim_attempts')
                ->where('id', $existingId)
                ->update(array_merge($data, ['updated_at' => $now]));
            return;
        }

        DB::table('item_claim_attempts')->insert(array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
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

    /**
     * 檢測上傳圖片是否為全黑、全白、純色或極度過暗之無效圖片
     */
    private function isBlankOrPitchDarkImage(string $rawBase64): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }

        $bytes = base64_decode($rawBase64, true);
        if ($bytes === false) {
            return false;
        }

        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            return false;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $size = 32;
        $thumb = imagecreatetruecolor($size, $size);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $size, $size, $w, $h);

        $lums = [];
        $maxLum = 0.0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $lums[] = $lum;
                if ($lum > $maxLum) {
                    $maxLum = $lum;
                }
            }
        }

        imagedestroy($img);
        imagedestroy($thumb);

        $n = count($lums);
        if ($n === 0) {
            return true;
        }
        $mean = array_sum($lums) / $n;
        $variance = 0.0;
        foreach ($lums as $lum) {
            $variance += ($lum - $mean) ** 2;
        }
        $stdDev = sqrt($variance / $n);

        // 純色（幾乎無任何紋理變化）或整張幾乎全黑無可見像素
        return $stdDev < 3.5 || $maxLum < 18.0;
    }

    /**
     * 檢查 VLM 對第 2 張現場驗證圖的客觀描述是否出現無效畫面關鍵字（雙重防呆過濾）
     */
    private function hasInvalidObservationKeywords(string $observation): bool
    {
        if ($observation === '') {
            return true;
        }

        // 先移除「不是全黑」「沒有模糊」等否定確認詞，避免誤傷正常清晰照片
        $sanitized = preg_replace('/(不是|並非|沒有|無|非)(全黑|全白|純色|模糊|過暗)/u', '', $observation) ?? $observation;
        $lower = mb_strtolower($sanitized);

        $invalidKeywords = [
            '全黑', '純黑', '黑屏', '全白', '純白', '純色', '單色',
            '模糊', '過暗', '太暗', '昏暗', '極暗',
            '看不出', '看不清', '無法辨識', '無法識別', '無法判斷',
            '無物品', '沒有物品', '無明確物體', '沒有明確物體', '沒有物體',
            '空白', '無畫面', '無內容', '無任何物體', '沒有任何物體',
            'pitch black', 'all black', 'all white', 'solid color', 'blank', 'too dark', 'blurry', 'no object', 'unrecognizable',
        ];

        foreach ($invalidKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    private function callOllamaVlm(Item $item, string $uploadedBase64, ?string $originalBase64): ?array
    {
        $ollamaUrl = rtrim(env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev'), '/');
        $vlmModel = env('OLLAMA_VLM_MODEL', 'qwen2.5vl');

        $images = $originalBase64 !== null
            ? [$originalBase64, $uploadedBase64]
            : [$uploadedBase64];

        $imageInstruction = $originalBase64 !== null
            ? <<<'INST'
你會收到兩張獨立的圖片：
- 第 1 張是資料庫登錄的「原失物照片」。
- 第 2 張是申請人現場拍攝上傳的「現場驗證照片」。

請嚴格遵守「先獨立觀察、再進行比對」的兩階段原則（適用於任何一般通用物品）：
1. 【嚴禁腦補與跨圖誘導】：觀察第 2 張「現場驗證照片」時，必須完全忽略第 1 張照片內容以及資料庫文字描述（品名、分類、說明），只能如實寫出第 2 張照片中肉眼實際看到的畫面。絕對不可把第 1 張的物體或文字標題套用到第 2 張上！
2. 【無效畫面一律判否】：只要第 2 張現場驗證照片為「全黑、全白、純色、過暗、嚴重模糊、空白或看不出任何明確物體」，必須在 image2_observation 如實寫出該現象，且必須直接判定 "is_match": false、"confidence": 0！
3. 【嚴格實物比對標準】：唯有第 1 張與第 2 張圖片各自都能清楚辨識出具體物體，且兩者的「物品種類（如水壺、耳機、鑰匙、雨傘、書包等一般通用物品）」、「主色系」與「主要外觀特徵」確實相符時，才可將 is_match 設為 true 並給予 70~100 的整數 confidence；若物品種類不同、顏色明顯不同、或第 2 張根本看不出與第 1 張相同的物體，必須判定為 false。
INST
            : <<<'INST'
資料庫原失物未附帶圖片，你會收到 1 張申請人現場拍攝的「現場驗證照片」（作為第 2 張待審核圖）。
請嚴格遵守以下審核原則（適用於任何一般通用物品）：
1. 【嚴禁文字誘導腦補】：觀察現場驗證照片（image2_observation）時，必須只根據畫面實際可見的物體如實描述，不可因資料庫文字描述而憑空腦補物體。
2. 【無效畫面一律判否】：若現場照片為「全黑、全白、純色、過暗、嚴重模糊、空白或看不出明確物體」，必須如實寫入 image2_observation，且直接判定 "is_match": false、"confidence": 0！
3. 【嚴格比對】：唯有畫面清楚拍到明確物品，且種類與外觀特徵與失物文字資訊相符時，才可設為 true 並給予 70~100 的整數 confidence。
INST;

        $prompt = <<<PROMPT
你是極度嚴謹、客觀公正的「校園失物招領系統」VLM 視覺審核專家。
{$imageInstruction}

資料庫登錄失物文字參考資訊（僅供最後比對階段參考，不可用於腦補第 2 張照片畫面）：
- 物品名稱：{$item->title}
- 物品分類：{$item->category}
- 地點：{$item->location}
- 補充說明：{$item->description}

請嚴格按照下列 JSON 欄位順序輸出純 JSON（不得包含 markdown 標記或額外說明文字，先獨立填寫 image1_observation 與 image2_observation，最後再給出判斷結果與 0~100 整數 confidence）：
{
  "image1_observation": "客觀描述第 1 張圖實際看到的物品種類、顏色與外觀（若無第 1 張圖請填：無原失物照片）",
  "image2_observation": "嚴格只描述第 2 張圖實際看到的畫面；若全黑、全白、純色、過暗、嚴重模糊或看不出明確物體，請如實寫出",
  "is_match": false,
  "confidence": 0,
  "reason": "使用繁體中文對比兩張圖的觀察結果，說明相符或不相符的具體依據"
}
PROMPT;

        try {
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
                    'num_predict' => 640,
                ],
            ]);

            $rawText = '';
            if ($response->successful()) {
                $rawText = trim((string) ($response->json('message.content') ?? $response->json('response', '')));
            } else {
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
                        'num_predict' => 640,
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

            $image1Observation = trim((string) ($decoded['image1_observation'] ?? ''));
            $image2Observation = trim((string) ($decoded['image2_observation'] ?? ''));
            $isMatch = filter_var($decoded['is_match'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $rawConf = $decoded['confidence'] ?? 0;
            if (is_string($rawConf)) {
                $rawConf = str_replace(['%', '分', ' '], '', $rawConf);
            }
            $confFloat = is_numeric($rawConf) ? (float) $rawConf : 0.0;
            if ($confFloat > 0.0 && $confFloat <= 1.0) {
                $confFloat *= 100.0;
            }
            $confidence = max(0, min(100, (int) round($confFloat)));

            return [
                'image1_observation' => $image1Observation,
                'image2_observation' => $image2Observation,
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
