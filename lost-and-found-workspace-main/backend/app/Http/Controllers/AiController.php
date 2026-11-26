<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    public function aiMatch(Request $request)
    {
        try {
            $userDescription = trim($request->input('userDescription', ''));

            if (empty($userDescription)) {
                return response()->json([
                    'success' => true,
                    'is_reasonable' => true,
                    'reasonableness_score' => 100,
                    'reason' => '',
                    'expanded_items' => [],
                    'expanded_locations' => [],
                    'matches' => [],
                ], 200);
            }

            // 基礎斷詞（作為 AI 逾時時的保底）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '遺失', '掉', '找', '尋', '求助', '東西', '物品', '找不到', '看到', '最後', '裡面', '裡'];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            $filteredWords = array_values(array_filter($words, function ($word) use ($stopWords) {
                return mb_strlen($word) > 1 && !in_array($word, $stopWords);
            }));

            $cacheKey = 'ai_semantic_v2_' . md5($userDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {
                $ollamaUrl = rtrim(env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev'), '/');
                $model = env('OLLAMA_TEXT_MODEL', 'llama3:latest');

                $systemPrompt = <<<'PROMPT'
你是一位專業的「校園AI尋物通」失物招領語意分析與防呆稽核專家，必須嚴格遵守以下規則並只輸出合法的純 JSON（不得包含 markdown 標記、註解或額外說明文字）：

任務目標：
1. 【全品項與品牌型號聯想 (expanded_items)】：
   - 從使用者描述中提取核心物品，並進行最大化語意擴展，包含：主流品牌、常見型號、中英文俗稱、材質、外觀特徵與常見配件。
   - 範例：若輸入「手機」，應擴展為 ["手機", "智慧型手機", "iPhone", "iPhone 13", "iPhone 14", "iPhone 15", "iPhone 16", "Samsung", "Galaxy", "Google Pixel", "ASUS", "ROG Phone", "Zenfone", "OPPO", "Sony", "Xperia", "小米", "Redmi", "手機殼", "保護殼", "鏡頭貼"]。
   - 若無明確物品則回傳空陣列 []。
2. 【校園地點同義詞擴展 (expanded_locations)】：
   - 提取校園地點並自動擴展正式全稱、簡稱、大樓別稱、樓層與教室編號寫法（含空格/無空格/「教室」/「館」變體）。
   - 範例：若輸入「綜三502教室」，應擴展為 ["綜三502教室", "綜三502", "綜三 502", "綜三館502", "綜合教學三館502", "綜合領域三館502教室", "綜合三館502教室", "綜三"]。
   - 若無明確地點則回傳空陣列 []。
3. 【常識與邏輯合理性評估 (is_reasonable, reasonableness_score, reason)】：
   - 比對「遺失物品」與「遺失地點」在現實校園生活中的合理性，偵測惡作劇、亂打字、超現實或違反物理常識的描述（例如：「大象掉在飲水機裡」、「核彈在鉛筆盒」、「恐龍在男廁」、「asdflkj」）。
   - 若描述合理（正常校園失物情境，或僅輸入物品/僅輸入地點），is_reasonable 設為 true，reasonableness_score 給 70~100。
   - 若為明顯惡作劇、毫無意義亂碼或完全不合常理，is_reasonable 設為 false，reasonableness_score 給 0~39，並在 reason 用繁體中文簡潔說明不合理之處。
4. 【輸出格式（嚴格純 JSON）】：
   {
     "expanded_items": ["物品與品牌型號關鍵字1", "關鍵字2"],
     "expanded_locations": ["地點同義詞1", "地點同義詞2"],
     "is_reasonable": true,
     "reasonableness_score": 95,
     "reason": "描述符合日常校園失物情境。"
   }
PROMPT;

                $userPrompt = "使用者輸入的尋物描述如下，請依規範回傳純 JSON：\n「{$userDescription}」";

                try {
                    $response = Http::withHeaders([
                        'ngrok-skip-browser-warning' => 'true',
                    ])->timeout(30)->post("{$ollamaUrl}/api/generate", [
                        'model' => $model,
                        'system' => $systemPrompt,
                        'prompt' => $userPrompt,
                        'stream' => false,
                        'format' => 'json',
                        'options' => [
                            'temperature' => 0.2,
                            'num_predict' => 512,
                        ],
                    ]);

                    if ($response->successful()) {
                        $rawText = trim($response->json('response', ''));
                        $cleanJson = trim(str_replace(['```json', '```', '`'], '', $rawText));
                        $firstBrace = strpos($cleanJson, '{');
                        $lastBrace = strrpos($cleanJson, '}');

                        if ($firstBrace !== false && $lastBrace !== false) {
                            $jsonString = substr($cleanJson, $firstBrace, $lastBrace - $firstBrace + 1);
                            $decoded = json_decode($jsonString, true);

                            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                $aiData = [
                                    'expanded_items' => array_values(array_filter((array) ($decoded['expanded_items'] ?? []), fn($v) => is_string($v) && mb_strlen(trim($v)) > 1)),
                                    'expanded_locations' => array_values(array_filter((array) ($decoded['expanded_locations'] ?? []), fn($v) => is_string($v) && mb_strlen(trim($v)) > 1)),
                                    'is_reasonable' => isset($decoded['is_reasonable']) ? (bool) $decoded['is_reasonable'] : true,
                                    'reasonableness_score' => max(0, min(100, (int) ($decoded['reasonableness_score'] ?? 85))),
                                    'reason' => (string) ($decoded['reason'] ?? ''),
                                ];
                                Cache::put($cacheKey, $aiData, 600);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Ollama 動態解析例外: ' . $e->getMessage());
                }
            }

            $isReasonable = $aiData['is_reasonable'] ?? true;
            $reasonablenessScore = $aiData['reasonableness_score'] ?? 85;
            $reason = $aiData['reason'] ?? '';
            $expandedItems = !empty($aiData['expanded_items']) ? $aiData['expanded_items'] : $filteredWords;
            $expandedLocations = $aiData['expanded_locations'] ?? [];

            $expandedItems = array_values(array_unique(array_map('trim', $expandedItems)));
            $expandedLocations = array_values(array_unique(array_map('trim', $expandedLocations)));

            // 若 AI 判定為惡作劇或不合常理，立即阻斷並告知前端原因
            if ($isReasonable === false || $reasonablenessScore < 40) {
                return response()->json([
                    'success' => true,
                    'is_reasonable' => false,
                    'reasonableness_score' => $reasonablenessScore,
                    'reason' => $reason !== '' ? $reason : '系統判斷此描述與現實校園失物常理不符，請重新輸入真實物品與地點特徵。',
                    'expanded_items' => $expandedItems,
                    'expanded_locations' => $expandedLocations,
                    'matches' => [],
                ], 200);
            }

            // 廣域撈出未結案（review_status != 'completed'）的候選物品
            $candidates = Item::where(function ($q) {
                $q->whereNull('review_status')
                  ->orWhere('review_status', '!=', 'completed');
            })
            ->where(function ($query) use ($expandedItems, $expandedLocations, $userDescription) {
                foreach ($expandedItems as $itm) {
                    $query->orWhere('title', 'ILIKE', "%{$itm}%")
                          ->orWhere('category', 'ILIKE', "%{$itm}%")
                          ->orWhere('description', 'ILIKE', "%{$itm}%");
                }
                foreach ($expandedLocations as $loc) {
                    $query->orWhere('location', 'ILIKE', "%{$loc}%");
                }
                if (mb_strlen($userDescription) > 1) {
                    $query->orWhere('title', 'ILIKE', "%{$userDescription}%");
                }
            })
            ->latest('id')
            ->get();

            // 分層權重計算：第一個核心品項視為最高優先，其餘視為聯想詞，結合地點同義詞排序
            $primaryItem = $expandedItems[0] ?? '';
            $secondaryItems = array_slice($expandedItems, 1);

            $scoredItems = $candidates->map(function ($item) use ($primaryItem, $secondaryItems, $expandedLocations) {
                $title = mb_strtolower($item->title ?? '');
                $category = mb_strtolower($item->category ?? '');
                $description = mb_strtolower($item->description ?? '');
                $location = mb_strtolower($item->location ?? '');

                $matchPrimary = false;
                if ($primaryItem !== '') {
                    $kw = mb_strtolower($primaryItem);
                    $matchPrimary = mb_strpos($title, $kw) !== false
                        || mb_strpos($category, $kw) !== false
                        || mb_strpos($description, $kw) !== false;
                }

                $matchExpanded = false;
                if (!$matchPrimary) {
                    foreach ($secondaryItems as $syn) {
                        $kw = mb_strtolower($syn);
                        if ($kw !== '' && (
                            mb_strpos($title, $kw) !== false
                            || mb_strpos($category, $kw) !== false
                            || mb_strpos($description, $kw) !== false
                        )) {
                            $matchExpanded = true;
                            break;
                        }
                    }
                }

                $matchLocation = false;
                foreach ($expandedLocations as $loc) {
                    $kw = mb_strtolower($loc);
                    if ($kw !== '' && mb_strpos($location, $kw) !== false) {
                        $matchLocation = true;
                        break;
                    }
                }

                $score = 0;
                if ($matchPrimary && $matchLocation) {
                    $score = 100;
                } elseif ($matchExpanded && $matchLocation) {
                    $score = 80;
                } elseif ($matchPrimary) {
                    $score = 60;
                } elseif ($matchExpanded) {
                    $score = 40;
                } elseif ($matchLocation) {
                    $score = 10;
                }

                $item->match_score = $score;
                return $item;
            })->filter(fn($item) => $item->match_score > 0);

            if ($scoredItems->contains(fn($item) => $item->match_score >= 40)) {
                $scoredItems = $scoredItems->filter(fn($item) => $item->match_score >= 40);
            }

            $finalMatches = $scoredItems->sortByDesc(fn($item) => [$item->match_score, $item->id])->values();
            $finalMatches->each(function ($item) {
                unset($item->match_score);
            });

            return response()->json([
                'success' => true,
                'is_reasonable' => true,
                'reasonableness_score' => $reasonablenessScore,
                'reason' => $reason,
                'expanded_items' => $expandedItems,
                'expanded_locations' => $expandedLocations,
                'matches' => $finalMatches,
            ], 200);
        } catch (\Exception $e) {
            Log::error('AI 比對發生嚴重錯誤: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器發生錯誤：' . $e->getMessage(),
                'matches' => [],
            ], 500);
        }
    }
}
