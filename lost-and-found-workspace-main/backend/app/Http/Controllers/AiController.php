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

            // 1. 基礎文字拆解（作為 AI 逾時時的保底）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品', '找不到', '看到', '最後', '裡面', '裡'];
            $words = preg_split('/[\s,\p{P}]+/u',$userDescription, -1, PREG_SPLIT_NO_EMPTY);
            
            $filteredWords = array_values(array_filter($words, function ($word) use ($stopWords) {
                return mb_strlen($word) > 1 && !in_array($word,$stopWords);
            }));

            // 2. 透過 Ollama 動態分離「核心物品原名」、「同義詞聯想」與「地點」（不寫死任何物品）
            $cacheKey = 'ai_ranked_match_' . md5($userDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {$ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    $prompt = "你是一個失物招領 AI 語意專家。使用者輸入了尋物描述：「{$userDescription}」。\n" .
                        "請針對任何物品與情境進行語意分析，並嚴格分離出三個部分：\n" .
                        "1. exact_items：使用者明確提到的【核心物品原名】（若無明確名詞則留空陣列）。\n" .
                        "2. synonyms：你根據語意自由聯想出的【同義詞、品牌、俗稱、泛稱或相關物品】（請發揮最大聯想力）。\n" .
                        "3. locations：使用者提到的【地點或相關場所同義詞】（若無則留空陣列）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含 markdown 標記（如 ```json）或任何廢話，格式如下：\n" .
                        "{\"exact_items\": [\"原名\"], \"synonyms\": [\"同義詞1\", \"同義詞2\"], \"locations\": [\"地點1\"]}";

                    $response = Http::timeout(25)->post("{$ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.6,
                            'num_predict' => 150
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
                                if (!empty($aiData['exact_items']) || !empty($aiData['synonyms']) || !empty($aiData['locations'])) {
                                    Cache::put($cacheKey, $aiData, 600);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Ollama 動態解析例外: ' . $e->getMessage());
                }
            }

            // 整理三組關鍵字（確保 AI 失敗時退回拆解詞彙）
            $exactItems = !empty($aiData['exact_items']) ? $aiData['exact_items'] : $filteredWords;
            $synonyms = $aiData['synonyms'] ?? [];
            $locations = $aiData['locations'] ?? [];

            // 過濾掉長度 <= 1 的干擾字，避免「件」、「在」誤抓不相干物品
            $exactItems = array_values(array_unique(array_filter($exactItems, fn($v) => mb_strlen(trim($v)) > 1)));
            $synonyms = array_values(array_unique(array_filter($synonyms, fn($v) => mb_strlen(trim($v)) > 1 && !in_array($v, $exactItems))));
            $locations = array_values(array_unique(array_filter($locations, fn($v) => mb_strlen(trim($v)) > 1)));

            // 3. 廣域撈出所有潛在候選資料（聯集：只要命中原名、同義詞或地點其中之一先撈出，再到記憶體精準排序）
            $candidates = Item::where(function ($query) use ($exactItems, $synonyms, $locations, $userDescription) {
                foreach ($exactItems as $itm) {
                    $query->orWhere('title', 'ILIKE', "%{$itm}%")
                          ->orWhere('category', 'ILIKE', "%{$itm}%");
                }
                foreach ($synonyms as $syn) {
                    $query->orWhere('title', 'ILIKE', "%{$syn}%")
                          ->orWhere('category', 'ILIKE', "%{$syn}%");
                }
                foreach ($locations as $loc) {
                    $query->orWhere('location', 'ILIKE', "%{$loc}%");
                }
                if (mb_strlen($userDescription) > 1) {
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 4. 進行「物品優先 + 同義詞次之 + 相同地點置頂」的分層權重計算
            $scoredItems = $candidates->map(function ($item) use ($exactItems, $synonyms, $locations) {
                $title = mb_strtolower($item->title ?? '');
                $category = mb_strtolower($item->category ?? '');
                $location = mb_strtolower($item->location ?? '');

                $matchExact = false;
                foreach ($exactItems as $itm) {
                    $kw = mb_strtolower(trim($itm));
                    if ($kw !== '' && (mb_strpos($title, $kw) !== false || mb_strpos($category, $kw) !== false)) {
                        $matchExact = true;
                        break;
                    }
                }

                $matchSynonym = false;
                if (!$matchExact) {
                    foreach ($synonyms as $syn) {
                        $kw = mb_strtolower(trim($syn));
                        if ($kw !== '' && (mb_strpos($title, $kw) !== false || mb_strpos($category, $kw) !== false)) {
                            $matchSynonym = true;
                            break;
                        }
                    }
                }

                $matchLocation = false;
                foreach ($locations as $loc) {
                    $kw = mb_strtolower(trim($loc));
                    if ($kw !== '' && mb_strpos($location, $kw) !== false) {
                        $matchLocation = true;
                        break;
                    }
                }

                // 權重分數（分數越高排越前面）：
                // 100分：相同物品 + 相同地點（第一順位）
                //  80分：同義詞物品 + 相同地點（並列第一順位，略低於原名）
                //  60分：相同物品（不同地點，第二順位）
                //  40分：同義詞物品（不同地點，第三順位）
                //  10分：僅地點相同（第四順位保底）
                $score = 0;
                if ($matchExact && $matchLocation) {
                    $score = 100;
                } elseif ($matchSynonym && $matchLocation) {
                    $score = 80;
                } elseif ($matchExact) {
                    $score = 60;
                } elseif ($matchSynonym) {
                    $score = 40;
                } elseif ($matchLocation) {
                    $score = 10;
                }

                $item->match_score = $score;
                return $item;
            })
            ->filter(fn($item) => $item->match_score > 0);

            // 5. 若有找到任何「物品或同義詞」（分數 >= 40），排除掉「只有地點對但物品完全無關」的雜訊（分數 10）
            $hasItemMatch = $scoredItems->contains(fn($item) => $item->match_score >= 40);
            if ($hasItemMatch) {
                $scoredItems = $scoredItems->filter(fn($item) => $item->match_score >= 40);
            }

            // 依照權重分數由高到低排序（同分則維持最新 id 在前）
            $finalMatches = $scoredItems->sortByDesc(function ($item) {
                return [$item->match_score, $item->id];
            })->values();

            // 移除暫時用來排序的屬性，保持乾淨 JSON
            $finalMatches->each(function ($item) {
                unset($item->match_score);
            });

            return response()->json([
                'success' => true,
                'matches' => $finalMatches
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