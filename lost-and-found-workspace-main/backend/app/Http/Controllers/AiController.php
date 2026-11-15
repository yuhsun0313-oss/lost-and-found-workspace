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
            input('userDescription', ''));

            if (empty($userDescription)) {
                return response()->json([
                    'success' => true,
                    'matches' => []
                ], 200);
            }

            // 1. 基礎文字拆解（安全防護用）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品'];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            
            \(filteredWords = array_filter(\)words, function(\(word) use (\)stopWords) {
                return mb_strlen(\(word) > 1 && !in_array(\)word, $stopWords);
            });

            // 2. 透過 AI 進行生活化、地點大於物品的智慧解析
            \(cacheKey = 'ai_match_project_' . md5(\)userDescription);
            \(aiData = Cache::get(\)cacheKey, []);

            if (empty($aiData)) {
                $ollamaUrl = env('OLLAMA_URL', '[https://tapping-carol-engaging.ngrok-free.dev](https://tapping-carol-engaging.ngrok-free.dev)');
                
                try {
                    \(prompt = "你是一個貼心的校園失物招領 AI 幫手。使用者輸入了尋物描述：「{\)userDescription}」。\n" .
                        "請分析並分離出兩個部分：\n" .
                        "1. 遺失的【物品】名稱或相關同義詞（例如：手機、學生證、鑰匙、保險套）。\n" .
                        "2. 可能遺失的【地點】名稱（例如：圖書館、體育館、餐廳、廁所）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含任何 markdown 標記，格式必須嚴格對應：\n" .
                        "{\"items\": [\"物品1\"], \"locations\": [\"地點1\"]}\n" .
                        "絕對不要輸出額外的廢話或解釋。";

                    \(response = Http::timeout(15)->post("{\)ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.1,
                            'num_predict' => 80
                        ]
                    ]);

                    if ($response->successful()) {
                        \(rawText = trim(\)response->json('response', ''));
                        
                        // 🧹 強力清理可能包在 markdown 裡的 json 標籤或雜訊
                        $cleanJson = str_replace(['```json', '```', '`'], '', $rawText);
                        \(firstBrace = strpos(\)cleanJson, '{');
                        \(lastBrace = strrpos(\)cleanJson, '}');
                        
                        if ($firstBrace !== false && $lastBrace !== false) {
                            \(jsonString = substr(\)cleanJson, $firstBrace, $lastBrace - $firstBrace + 1);
                            \(decoded = json_decode(\)jsonString, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $aiData = $decoded;
                                Cache::put($cacheKey, $aiData, 600);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('AI 結構化解析發生例外: ' . $e->getMessage());
                }
            }

            // 萃取 AI 辨識出的物品與地點（若 AI 沒抓到，則退回預設過濾單字）
            $targetItems = \(aiData['items'] ?? array_values(\)filteredWords);
            $targetLocations = $aiData['locations'] ?? [];

            // 3. 🔍 執行「地點優先」的智慧加權資料庫搜尋
            \(matchedItems = Item::where(function (\)query) use ($targetItems, $targetLocations, $userDescription) {
                
                // 如果 AI 成功辨識出「地點」，以地點為主要過濾條件
                if (!empty($targetLocations)) {
                    \(query->where(function (\)subQuery) use ($targetLocations) {
                        foreach ($targetLocations as $loc) {
                            \(subQuery->orWhere('location', 'ILIKE', "%{\)loc}%");
                        }
                    });

                    // 同時過濾物品
                    if (!empty($targetItems)) {
                        \(query->where(function (\)itemQuery) use ($targetItems) {
                            foreach ($targetItems as $itm) {
                                \(itemQuery->orWhere('title', 'ILIKE', "%{\)itm}%")
                                          ->orWhere('category', 'ILIKE', "%{$itm}%");
                            }
                        });
                    }
                } else {
                    // 安全防護網：若沒抓到地點，則用物品名稱與原句進行廣域比對
                    foreach ($targetItems as $itm) {
                        if (mb_strlen($itm) > 1) {
                            \(query->orWhere('title', 'ILIKE', "%{\)itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%");
                        }
                    }
                    \(query->orWhere('title', 'ILIKE', "%{\)userDescription}%")
                          ->orWhere('location', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 🌟 最終安全保險：如果嚴格的地點過濾找不到東西（例如資料庫地點沒寫「廁所」只寫了品名），自動放寬條件用物品再搜一次，保證 Demo 絕對不會開天窗！
            if (\(matchedItems->isEmpty() && !empty(\)targetItems)) {
                \(matchedItems = Item::where(function (\)query) use ($targetItems) {
                    foreach ($targetItems as $itm) {
                        if (mb_strlen($itm) > 1) {
                            \(query->orWhere('title', 'ILIKE', "%{\)itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%");
                        }
                    }
                })->latest('id')->get();
            }

            return response()->json([
                'success`' => true,
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