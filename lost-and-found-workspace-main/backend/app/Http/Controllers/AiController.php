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

            $keywords = [$userDescription];
            $words = preg_split('/[\s,\p{P}]+/u', $userDescription, -1, PREG_SPLIT_NO_EMPTY);
            if (!empty($words)) {
                $keywords = array_merge($keywords, $words);
            }

            // 1. 🛡️ 智慧快取修正：先從快取讀取
            $cacheKey = 'ai_match_' . md5($userDescription);
            $aiKeywords = Cache::get($cacheKey, []);

            // 如果快取沒有，才真正去打 Ollama
            if (empty($aiKeywords)) {
                $ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
                
                try {
                    $prompt = "你是一個貼心的校園失物招領 AI 幫手。使用者輸入了尋物描述：「{$userDescription}」。\n" .
                        "請發揮你的智慧，幫忙分析並萃取出兩個關鍵資訊：\n" .
                        "1. 遺失的【物品】名稱或相關同義詞、品牌（例如：手機、學生證、鑰匙、筆電、雨傘）。\n" .
                        "2. 可能遺失的【地點】名稱（例如：圖書館、體育館、餐廳、201教室）。\n" .
                        "規則：請務必以純 JSON 格式回覆，絕對不要包含任何 markdown 標記（如 ```json），格式必須嚴格對應：\n" .
                        "{\"items\": [\"物品1\", \"物品2\"], \"locations\": [\"地點1\", \"地點2\"]}\n" .
                        "絕對不要輸出額外的廢話、問候語或解釋，只要輸出最乾淨的 JSON 字串即可。";

                    $response = Http::timeout(20)->post("{$ollamaUrl}/api/generate", [
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
                        $parsed = array_map('trim', explode(',', $cleanedText));
                        $aiKeywords = array_filter($parsed);

                        // 🌟 關鍵修正：唯有當 AI 確實產出關鍵字時，才寫入快取 10 分鐘！絕不快取空結果
                        if (!empty($aiKeywords)) {
                            Cache::put($cacheKey, $aiKeywords, 600);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Ollama 聯想過程發生例外: ' . $e->getMessage());
                }
            }

            if (!empty($aiKeywords)) {
                $keywords = array_merge($keywords, $aiKeywords);
            }

            // 過濾掉重複與空白的關鍵字
            $keywords = array_unique(array_filter($keywords));

            // 2. 🔍 資料庫全面廣域模糊比對（使用 PostgreSQL 的 ILIKE 進行不分大小寫搜尋）
            $matchedItems = Item::where(function ($query) use ($keywords, $userDescription) {
                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if ($cleanKeyword !== '') {
                        $query->orWhere('title', 'ILIKE', "%{$cleanKeyword}%")
                              ->orWhere('category', 'ILIKE', "%{$cleanKeyword}%")
                              ->orWhere('location', 'ILIKE', "%{$cleanKeyword}%");
                    }
                }
                
                // 額外保險
                $query->orWhere('title', 'ILIKE', "%{$userDescription}%")
                      ->orWhere('location', 'ILIKE', "%{$userDescription}%");
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