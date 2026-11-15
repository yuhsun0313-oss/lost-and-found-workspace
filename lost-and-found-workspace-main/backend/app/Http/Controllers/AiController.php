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

            // 1. 預先放入使用者原句與拆解詞，確保擁有最強的基礎搜尋能力
            $keywords = [$userDescription];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($words)) {
                $keywords = array_merge($keywords, $words);
            }

            // 2. 🛡️ 智慧快取機制：如果 10 分鐘內查過相同的句子，直接複用上次 AI 的結果，避免打爆 ngrok 頻率限制！
            $cacheKey = 'ai_match_' . md5($userDescription);
            $aiKeywords = Cache::remember($cacheKey, 600, function () use ($userDescription) {
                $ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    $prompt = "你是一個失物招領 AI 聯想專家。使用者輸入了尋物描述：「{$userDescription}」。\n" .
                        "請完全發揮你的語意聯想與推測能力，不管使用者輸入什麼口語、代名詞、地點或模糊形容，請自由聯想出所有可能相關的物品名稱、同義詞、品牌、類別或情境關鍵字。\n" .
                        "請列出 5 到 8 個相關關鍵字。\n" .
                        "規則：絕對不要講任何廢話、不要解釋、不要舉例，只要輸出用半形逗號（,）分隔的關鍵字串即可。";

                    $response = Http::timeout(15)->post("{$ollamaUrl}/api/generate", [
                        'model' => 'llama3:latest',
                        'prompt' => $prompt,
                        'stream' => false,
                        'options' => [
                            'temperature' => 0.7,
                            'num_predict' => 60
                        ]
                    ]);

                    if ($response->successful()) {
                        $aiText = trim($response->json('response', ''));
                        $cleanedText = str_replace(["\r", "\n", '"', "'", "。", "：", "、"], ["", "", "", "", ",", ",", ","], $aiText);
                        return array_map('trim', explode(',', $cleanedText));
                    }
                } catch (\Exception $e) {
                    Log::warning('Ollama 聯想過程發生例外: ' . $e->getMessage());
                }

                return [];
            });

            if (!empty($aiKeywords)) {
                $keywords = array_merge($keywords, $aiKeywords);
            }

            // 過濾掉重複與空白的關鍵字
            $keywords = array_unique(array_filter($keywords));

            // 3. 🔍 資料庫全面廣域模糊比對
            $matchedItems = Item::where(function ($query) use ($keywords, $userDescription) {
                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if ($cleanKeyword !== '') {
                        $query->orWhere('title', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('category', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('location', 'LIKE', "%{$cleanKeyword}%");
                    }
                }
                
                // 額外保險
                $query->orWhere('title', 'LIKE', "%{$userDescription}%")
                      ->orWhere('location', 'LIKE', "%{$userDescription}%");
            })
            ->latest('id')
            ->get();

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