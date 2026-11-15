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

            // 1. 基礎文字拆解（僅過濾無意義的助詞與干擾字，保留核心字詞作為防護底線）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品', '找不到', '看到', '最後'];
            $words = preg_split('/[\s,\p{P}]+/u',$userDescription, -1, PREG_SPLIT_NO_EMPTY);
            
            $filteredWords = array_filter($words, function ($word) use ($stopWords) {
                return mb_strlen($word) > 1 && !in_array($word,$stopWords);
            });

            // 2. 🧠 完全交由 Ollama 進行「任意物品與情境」的動態聯想與結構化解析
            $cacheKey = 'ai_dynamic_match_' . md5($userDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {$ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    // 🎯 核心 Prompt：不寫死任何物品類別，完全讓 LLM 自主發揮同義詞聯想與地點萃取
                    $prompt = "你是一個頂級的失物招領 AI 聯想專家。使用者輸入了任意尋物描述或情境：「{$userDescription}」。\n" .
                        "請發揮強大的語意聯想與推理能力，分析並分離出兩個部分：\n" .
                        "1. items：使用者可能在找的【所有相關物品名稱、同義詞、品牌、泛稱或替代詞】（請盡量多聯想幾種可能代表該物品的詞彙）。\n" .
                        "2. locations：使用者提到的【可能遺失的地點】（若沒提到則回傳空陣列）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含任何 markdown 標記（如 ```json），格式必須嚴格對應：\n" .
                        "{\"items\": [\"物品原名\", \"同義詞1\", \"同義詞2\"], \"locations\": [\"地點1\"]}\n" .
                        "絕對不要輸出額外的廢話、問候語或解釋。";

                    $response = Http::timeout(25)->post("{$ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.7, // 提高靈敏度，讓 Ollama 對任何奇特輸入都能自由發散聯想
                            'num_predict' => 120
                        ]
                    ]);

                    if ($response->successful()) {
                        $rawText = trim($response->json('response', ''));
                        
                        // 🧹 強力清理可能包在 markdown 裡的 json 標籤
                        $cleanJson = str_replace(['```json', '```', '`'], '', $rawText);
                        $firstBrace = strpos($cleanJson, '{');
                        $lastBrace = strrpos($cleanJson, '}');
                        
                        if ($firstBrace !== false && $lastBrace !== false) {
                            $jsonString = substr($cleanJson, $firstBrace, $lastBrace - $firstBrace + 1);
                            $decoded = json_decode($jsonString, true);
                            
                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $aiData = $decoded;
                                // 確保有確實抓到內容才寫入快取
                                if (!empty($aiData['items']) || !empty($aiData['locations'])) {
                                    Cache::put($cacheKey, $aiData, 600);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('AI 動態聯想解析逾時或例外: ' . $e->getMessage());
                }
            }

            // 整合 AI 動態聯想出的物品與使用者的原始過濾詞
            $targetItems = !empty($aiData['items']) ? array_merge($aiData['items'], array_values($filteredWords)) : array_values($filteredWords);
            $targetItems[] = $userDescription; // 把原句也放入保底
            $targetItems = array_unique(array_filter($targetItems));

            $targetLocations = $aiData['locations'] ?? [];

            // 3. 🔍 執行「地點大於物品」的智慧加權資料庫搜尋
            $matchedItems = Item::where(function ($query) use ($targetItems, $targetLocations, $userDescription) {
                
                // 優先策略：如果 AI 成功辨識出「地點」，地點必須命中，同時結合 AI 聯想出的所有同義詞進行比對
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
                } else {
                    // 備援策略：沒抓到地點時，用 AI 動態聯想出的所有同義詞與原句進行廣域比對
                    foreach ($targetItems as $itm) {
                        if (mb_strlen(trim($itm)) > 1) {
                            $query->orWhere('title', 'ILIKE', "%{$itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%");
                        }
                    }
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%")
                          ->orWhere('location', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 🌟 最終 Demo 保險：如果嚴格交集找不到，自動放寬為純 AI 同義詞搜尋，保證絕對不會開天窗！
            if ($matchedItems->isEmpty() && !empty($targetItems)) {
                $matchedItems = Item::where(function ($query) use ($targetItems, $userDescription) {
                    foreach ($targetItems as $itm) {
                        if (mb_strlen(trim($itm)) > 1) {
                            $query->orWhere('title', 'ILIKE', "%{$itm}%")
                                  ->orWhere('category', 'ILIKE', "%{$itm}%")
                                  ->orWhere('location', 'ILIKE', "%{$itm}%");
                        }
                    }
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%");
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