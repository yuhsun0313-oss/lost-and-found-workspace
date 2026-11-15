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
            $userDescription = trim($request->input('userDescription', ''));

            if (empty($userDescription)) {
                return response()->json([
                    'success' => true,
                    'matches' => []
                ], 200);
            }

            // 1. 基礎文字拆解（過濾干擾字）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品', '找不到', '看到', '最後'];
            $words = preg_split('/[\s,\p{P}]+/u',$userDescription, -1, PREG_SPLIT_NO_EMPTY);
            
            $filteredWords = array_filter($words, function ($word) use ($stopWords) {
                return mb_strlen($word) > 1 && !in_array($word,$stopWords);
            });

            // 2. 🧠 透過 Ollama 進行動態聯想與結構化解析
            $cacheKey = 'ai_dynamic_match_v4_' . md5($userDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {$ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    $prompt = "你是一個頂級的失物招領 AI 聯想專家。使用者輸入了任意尋物描述或情境：「{$userDescription}」。\n" .
                        "請發揮強大的語意聯想與推理能力，分析並分離出兩個部分：\n" .
                        "1. items：使用者可能在找的【所有相關物品名稱、同義詞、品牌、泛稱或替代詞】（請盡量多聯想幾種可能代表該物品的詞彙）。\n" .
                        "2. locations：使用者提到的【可能遺失的地點】（若沒提到任何地點，請回傳空陣列 []）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含任何 markdown 標記（如 ```json），格式必須嚴格對應：\n" .
                        "{\"items\": [\"物品原名\", \"同義詞1\"], \"locations\": [\"地點1\"]}\n" .
                        "絕對不要輸出額外的廢話、問候語或解釋。";

                    $response = Http::timeout(25)->post("{$ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.7,
                            'num_predict' => 120
                        ]
                    ]);

                    if ($response->successful()) {
                        $rawText = trim($response->json('response', ''));
                        $cleanJson = str_replace(['```json', '```', '`'], '', $rawText);
                        $firstBrace = strpos($cleanJson, '{');
                        $lastBrace = strrpos($cleanJson, '}');
                        
                        if ($firstBrace !== false && $lastBrace !== false) {
                            $jsonString = substr($cleanJson, $firstBrace, $lastBrace - $firstBrace + 1);
                            $decoded = json_decode($jsonString, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $aiData = $decoded;
                                if (!empty($aiData['items'])) {
                                    Cache::put($cacheKey, $aiData, 600);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('AI 動態聯想解析逾時或例外: ' . $e->getMessage());
                }
            }

            $targetItems = !empty($aiData['items']) ? array_merge($aiData['items'], array_values($filteredWords)) : array_values($filteredWords);
            $targetItems[] = $userDescription;
            $targetItems = array_unique(array_filter($targetItems));

            $targetLocations = $aiData['locations'] ?? [];

            // 3. 🔍 智慧雙軌搜尋邏輯
            $matchedItems = Item::where(function ($query) use ($targetItems, $targetLocations, $userDescription) {
                
                // 軌道 A：當使用者「有明確提到地點」時，執行地點優先篩選
                if (!empty($targetLocations)) {
                    $query->where(function ($subQuery) use ($targetLocations) {
                        foreach ($targetLocations as $loc) {
                            if (mb_strlen(trim($loc)) > 1) {
                                $subQuery->orWhere('location', 'ILIKE', "%{$loc}%");
                            }
                        }
                    });

                    if (!empty($targetItems)) {
                        $query->where(function ($itemQuery) use ($targetItems) {
                            foreach ($targetItems as $itm) {
                                if (mb_strlen(trim($itm)) > 1) {
                                    $itemQuery->orWhere('title', 'ILIKE', "%{$itm}%")
                                              ->orWhere('category', 'ILIKE', "%{$itm}%");
                                }
                            }
                        });
                    }
                } 
                // 軌道 B：當使用者「只打物品或情境（無特定地點）」時，直接進行全面廣域同義詞模糊比對！
                else {
                    foreach ($targetItems as $itm) {
                        if (mb_strlen(trim($itm)) > 1) {
                            $query->orWhere('title', 'ILIKE', "%{$itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%")
                                  ->orWhere('location', 'ILIKE', "%{$itm}%");
                        }
                    }
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 🌟 最終 Demo 保險：如果找不到任何東西，強制用原句與拆解詞再撈一次
            if ($matchedItems->isEmpty()) {
                $matchedItems = Item::where(function ($query) use ($userDescription, $filteredWords) {
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%")
                          ->orWhere('category', 'ILIKE', "%{$userDescription}%");
                    foreach ($filteredWords as $w) {
                        if (mb_strlen($w) > 1) {
                            $query->orWhere('title', 'ILIKE', "%{$w}%")
                                  ->orWhere('category', 'ILIKE', "%{$w}%");
                        }
                    }
                })
                ->latest('id')
                ->get();
            }

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