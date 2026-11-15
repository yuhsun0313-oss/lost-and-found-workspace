<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Item;

class AiController extends Controller
{
    public function aiMatch(Request $request)
    {
        try {
            // 修正 1：補齊完整的 $userDescription 接收語法
            $userDescription = trim($request->input('userDescription', ''));

            if (empty($userDescription)) {
                return response()->json([
                    'success' => true,
                    'matches' => []
                ], 200);
            }

            // 1. 基礎文字拆解（過濾干擾字）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品', '找不到'];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            
            $filteredWords = array_filter($words, function ($word) use ($stopWords) {
                return mb_strlen($word) > 1 && !in_array($word, $stopWords);
            });

            // 2. 透過 AI 進行生活化、地點大於物品的智慧解析
            $cacheKey = 'ai_match_v2_' . md5($userDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {
                // 修正 2：還原乾淨的預設網址字串
                $ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    // 專題展示專用：生活化且嚴格分離「地點」與「物品」
                    $prompt = "你是一個貼心的校園失物招領 AI 幫手。使用者輸入了尋物描述：「{$userDescription}」。\n" .
                        "請分析並分離出兩個關鍵資訊：\n" .
                        "1. 遺失的【物品】名稱或相關同義詞、品牌（例如：手機、學生證、鑰匙、雨傘、水壺）。\n" .
                        "2. 可能遺失的【地點】名稱（例如：圖書館、體育館、餐廳、201教室、廁所）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含任何 markdown 標記（如 ```json），格式必須嚴格對應：\n" .
                        "{\"items\": [\"物品1\"], \"locations\": [\"地點1\"]}\n" .
                        "絕對不要輸出額外的廢話或解釋。";

                    $response = Http::timeout(20)->post("{$ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.1,
                            'num_predict' => 80
                        ]
                    ]);

                    if ($response->successful()) {
                        $rawText = trim($response->json('response', ''));
                        
                        // 清理可能包在 markdown 裡的 json 標籤
                        $cleanJson = str_replace(['```json', '```', '`'], '', $rawText);
                        $firstBrace = strpos($cleanJson, '{');
                        $lastBrace = strrpos($cleanJson, '}');
                        
                        if ($firstBrace !== false && $lastBrace !== false) {$jsonString = substr($cleanJson,$firstBrace, $lastBrace -$firstBrace + 1);
                            $decoded = json_decode($jsonString, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $aiData =$decoded;
                                // 只有成功抓到東西才寫入快取
                                if (!empty($aiData['items']) \vert{}\vert{} !empty($aiData['locations'])) {
                                    Cache::put($cacheKey, $aiData, 600); } } } } } catch (\Exception$e) {
                    Log::warning('AI 結構化解析發生例外: ' . $e->getMessage());
                }
            }

            // 萃取 AI 辨識出的物品與地點（若 AI 沒抓到，退回過濾後的關鍵字）
            $targetItems = !empty($aiData['items']) ? $aiData['items'] : array_values($filteredWords);
            $targetLocations =$aiData['locations'] ?? [];

            // 3. 執行「地點大於物品」的智慧加權資料庫搜尋
            $matchedItems = Item::where(function ($query) use ($targetItems, $targetLocations,$userDescription) {
                
                // 優先策略：如果 AI 成功辨識出「地點」，強制地點必須命中，同時結合物品比對
                if (!empty($targetLocations)) {$query->where(function ($subQuery) use ($targetLocations) {
                        foreach ($targetLocations as$loc) {
                            if (mb_strlen(trim($loc)) > 1) {
                                $subQuery->orWhere('location', 'ILIKE', "\%{$loc}%");
                            }
                        }
                    });

                    if (!empty($targetItems)) {$query->where(function ($itemQuery) use ($targetItems) {
                            foreach ($targetItems as$itm) {
                                if (mb_strlen(trim($itm)) > 1) {
                                    $itemQuery->orWhere('title', 'ILIKE', "\%{$itm}%")
                                              ->orWhere('category', 'ILIKE', "%{$itm}%");
                                }
                            }
                        });
                    }
                } else {
                    // 備援策略：沒抓到地點時，用物品名稱進行精準比對
                    foreach ($targetItems as$itm) {
                        if (mb_strlen(trim($itm)) > 1) {
                            $query->orWhere('title', 'ILIKE', "\%{$itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%");
                        }
                    }
                    $query->orWhere('title', 'ILIKE', "\%{$userDescription}%")
                          ->orWhere('location', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 安全防護網：如果「地點 + 物品」的嚴格交集找不到（例如失主記錯地點），自動放寬為只找該物品，避免畫面空白
            if ($matchedItems->isEmpty() && !empty($targetItems)) {$matchedItems = Item::where(function ($query) use ($targetItems) {
                    foreach ($targetItems as$itm) {
                        if (mb_strlen(trim($itm)) > 1) {
                            $query->orWhere('title', 'ILIKE', "\%{$itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%");
                        }
                    }
                })
                ->latest('id')
                ->get();
            }

            // 修正 3：移除 'success`' 裡面多餘的反引號
            return response()->json([
                'success' => true,
                'matches' => $matchedItems
            ], 200);

        } catch (\Exception $e) {
            Log::error('AI 比對發生嚴重錯誤: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器發生錯誤：' . $e->getMessage(),
                'matches' => []
            ], 500);
        }
    }
}