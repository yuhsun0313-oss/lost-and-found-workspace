<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiController extends Controller
{
    /**
     * 正規化尋物描述：移除語氣助詞、常見動詞時態詞綴與標點空白，
     * 確保「我在綜三不見筆電」與「我在綜三不見了筆電」產生完全一致的語意解析與搜尋結果。
     */
    private function normalizeQueryText(string $text): string
    {
        $normalized = trim($text);
        // 統一動詞 + 語氣詞綴
        $replacements = [
            '不見了' => '不見',
            '遺失了' => '遺失',
            '弄丟了' => '弄丟',
            '弄不見了' => '弄不見',
            '弄不見' => '不見',
            '掉了' => '掉',
            '找到了' => '找到',
            '撿到了' => '撿到',
            '忘記了' => '忘記',
            '留下了' => '留下',
            '落在' => '在',
            '掉在' => '在',
        ];
        $normalized = str_replace(array_keys($replacements), array_values($replacements), $normalized);

        // 移除句中或句尾純語氣助詞、時間副詞與標點空白
        $normalized = preg_replace('/[\s,\p{P}]+/u', '', $normalized);
        $normalized = preg_replace('/(好像|疑似|不小心|剛剛|昨天|今天|前天|我的|那個|一個|這支|這台|這張|這本|呢|嗎|吧|啊|呀|喔|啦|欸|耶|嘛|喔)+/u', '', $normalized);
        // 去掉在常見動詞或名詞間殘留的「了」「的」「著」「過」
        $normalized = preg_replace('/(了|的|著|過)+/u', '', $normalized);

        return trim($normalized) !== '' ? trim($normalized) : trim($text);
    }

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

            $canonicalDescription = $this->normalizeQueryText($userDescription);

            // 基礎斷詞（作為 AI 逾時時的保底）
            $stopWords = ['我', '你', '他', '的', '了', '在', '個', '件', '支', '張', '本', '昨天', '今天', '明天', '不見', '不見了', '遺失', '遺失了', '掉', '掉了', '弄丟', '弄丟了', '找', '尋', '求助', '東西', '物品', '找不到', '看到', '最後', '裡面', '裡'];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            $filteredWords = array_values(array_filter($words, function ($word) use ($stopWords) {
                $clean = str_replace(['了', '的', '嗎', '呢', '吧', '啊'], '', $word);
                return mb_strlen($clean) > 1 && !in_array($clean, $stopWords) && !in_array($word, $stopWords);
            }));

            $cacheKey = 'ai_semantic_v3_' . md5($canonicalDescription);
            $aiData = Cache::get($cacheKey, []);

            if (empty($aiData)) {
                $ollamaUrl = rtrim(env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev'), '/');
                $model = env('OLLAMA_TEXT_MODEL', 'llama3:latest');

                $systemPrompt = <<<'PROMPT'
你是一位專業的「校園AI尋物通」失物招領語意分析與防呆稽核專家，必須嚴格遵守以下規則並只輸出合法的純 JSON（不得包含 markdown 標記、註解或額外說明文字）：

核心一致性規則（極重要）：
- 在解析前，必須完全忽略句子中的語氣助詞、時態詞與冗贅連接詞（例如：「了」、「的」、「著」、「過」、「啊」、「呢」、「嗎」、「吧」、「呀」、「喔」、「啦」、「好像」、「疑似」、「不小心」、「在...裡」）。
- 只要核心「物品」與核心「地點」相同（例如「我在綜三不見筆電」與「我在綜三不見了筆電」），你必須提取出完全一致的核心關鍵字與擴展詞清單，並將最核心、最直接的物品名詞放在 expanded_items 的第一個元素！

任務目標：
1. 【全品項與品牌型號聯想 (expanded_items)】：
   - 提取核心物品並做最大化語意擴展，涵蓋主流品牌、常見型號、中英文俗稱、材質、外觀特徵與常見配件。
   - 第 1 個元素必須是核心物品標準簡稱（如「筆電」、「手機」、「水壺」），後面接著同義詞與品牌型號。
   - 範例：「筆電」-> ["筆電", "筆記型電腦", "Laptop", "MacBook", "MacBook Air", "MacBook Pro", "ASUS", "ROG", "ZenBook", "Acer", "Lenovo", "ThinkPad", "HP", "Dell", "MSI", "電腦包", "充電器"]。
   - 範例：「手機」-> ["手機", "智慧型手機", "iPhone", "iPhone 13", "iPhone 14", "iPhone 15", "iPhone 16", "Samsung", "Galaxy", "Google Pixel", "ASUS", "ROG Phone", "Zenfone", "OPPO", "Sony", "Xperia", "小米", "Redmi", "手機殼", "保護殼", "鏡頭貼"]。
   - 若無明確物品則回傳空陣列 []。
2. 【校園地點同義詞擴展 (expanded_locations)】：
   - 提取校園地點並自動擴展正式全稱、簡稱、大樓別稱、樓層與教室編號寫法（含空格/無空格/「教室」/「館」變體）。
   - 範例：「綜三502教室」-> ["綜三502教室", "綜三502", "綜三 502", "綜三館502", "綜合教學三館502", "綜合領域三館502教室", "綜合三館502教室", "綜三"]。
   - 若無明確地點則回傳空陣列 []。
3. 【常識與邏輯合理性評估 (is_reasonable, reasonableness_score, reason)】：
   - 比對「遺失物品」與「遺失地點」在現實校園生活中的合理性，偵測惡作劇、亂打字、超現實或違反物理常識的描述（例如：「大象掉在飲水機裡」、「核彈在鉛筆盒」、「恐龍在男廁」、「asdflkj」）。
   - 若描述合理（正常校園失物情境，或僅輸入物品/僅輸入地點），is_reasonable 設為 true，reasonableness_score 給 70~100。
   - 若為明顯惡作劇、毫無意義亂碼或完全不合常理，is_reasonable 設為 false，reasonableness_score 給 0~39，並在 reason 用繁體中文簡潔說明不合理之處。
4. 【輸出格式（嚴格純 JSON）】：
   {
     "expanded_items": ["核心物品", "同義詞或品牌型號1", "關鍵字2"],
     "expanded_locations": ["地點同義詞1", "地點同義詞2"],
     "is_reasonable": true,
     "reasonableness_score": 95,
     "reason": "描述符合日常校園失物情境。"
   }
PROMPT;

                $userPrompt = "使用者輸入的尋物描述（已去除語氣助詞標準化）如下，請依規範回傳純 JSON：\n「{$canonicalDescription}」";

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
                            'temperature' => 0.0,
                            'seed' => 42,
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
            ->where(function ($query) use ($expandedItems, $expandedLocations, $canonicalDescription) {
                foreach ($expandedItems as $itm) {
                    $query->orWhere('title', 'ILIKE', "%{$itm}%")
                          ->orWhere('category', 'ILIKE', "%{$itm}%")
                          ->orWhere('description', 'ILIKE', "%{$itm}%");
                }
                foreach ($expandedLocations as $loc) {
                    $query->orWhere('location', 'ILIKE', "%{$loc}%");
                }
                if (mb_strlen($canonicalDescription) > 1) {
                    $query->orWhere('title', 'ILIKE', "%{$canonicalDescription}%");
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
