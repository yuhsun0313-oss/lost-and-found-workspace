<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Item;

class AiController extends Controller
{
    public function aiMatch(Request $request)
    {
        try {
            $userDescription = $request->input('userDescription', '');

            if (empty(trim($userDescription))) {
                return response()->json([
                    'success' => true,
                    'matches' => []
                ], 200);
            }

            // 初始化關鍵字陣列，預先放入使用者的原始描述
            $keywords = [$userDescription];
            $ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
            
            try {
                // 🧠 完全開放、不加任何範例限制的純粹 AI 聯想 Prompt
                $prompt = "你是一個失物招領 AI 聯想專家。使用者輸入了尋物描述：「{$userDescription}」。\n" .
                    "請完全發揮你的語意聯想與推測能力，不管使用者輸入什麼口語、代名詞、地點或模糊形容，請自由聯想出所有可能相關的物品名稱、同義詞、品牌、類別或情境關鍵字。\n" .
                    "請列出 5 到 8 個相關關鍵字。\n" .
                    "規則：絕對不要講任何廢話、不要解釋、不要舉例，只要輸出用半形逗號（,）分隔的關鍵字串即可。";

                $response = Http::timeout(10)->post("{$ollamaUrl}/api/generate", [
                    'model' => 'llama3:latest',
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.8, // 提高靈敏度，讓 AI 發揮最大自由聯想空間
                        'num_predict' => 60
                    ]
                ]);

                if ($response->successful()) {
                    $aiText = trim($response->json('response', ''));
                    
                    // 🧹 強力清洗 AI 回傳的雜訊
                    $cleanedText = str_replace(["\r", "\n", '"', "'", "。", "：", "、"], ["", "", "", "", ",", ",", ","], $aiText);
                    $aiKeywords = array_map('trim', explode(',', $cleanedText));
                    
                    if (!empty($aiKeywords)) {
                        $keywords = array_merge($keywords, $aiKeywords);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Ollama 聯想過程發生例外: ' . $e->getMessage());
            }

            // 過濾掉重複與空白的關鍵字
            $keywords = array_unique(array_filter($keywords));

            // 🔍 資料庫模糊比對（對現有的 title, category, location 欄位進行廣域搜尋）
            $matchedItems = Item::where(function ($query) use ($keywords, $userDescription) {
                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if ($cleanKeyword !== '') {
                        $query->orWhere('title', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('category', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('location', 'LIKE', "%{$cleanKeyword}%");
                    }
                }
                
                // 額外保險：將使用者的原始描述也拿去對現有欄位做模糊比對
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