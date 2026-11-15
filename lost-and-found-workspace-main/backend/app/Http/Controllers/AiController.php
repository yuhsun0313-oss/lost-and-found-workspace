<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Item;

class AiController extends Controller
{
    public function match(Request $request)
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
                // 🧠 讓 Ollama 充分發揮語意聯想與推測能力的 Prompt
                $prompt = "你是一個頂級的失物招領 AI 聯想專家。使用者輸入了一段尋物描述：「{$userDescription}」。\n" .
                    "即使使用者的描述非常模糊、口語（例如：掉了一個東西、軟軟的、那支、不知道在哪裡），你也必須發揮強大的語意聯想力，猜測使用者可能在找什麼物品、可能有哪些同義詞、或者可能是在描述哪種類型的東西。\n" .
                    "請列出 5 到 8 個相關的物品名稱、同義詞、隱喻或可能的情境關鍵字。\n" .
                    "規則：絕對不要講任何廢話、不要解釋、不要客套，只要輸出用半形逗號（,）分隔的關鍵字即可（例如：飛機杯,情趣用品,玩具,東西,物品）。";

                $response = Http::timeout(10)->post("{$ollamaUrl}/api/generate", [
                    'model' => 'llama3:latest',
                    'prompt' => $prompt,
                    'stream' => false,
                    'options' => [
                        'temperature' => 0.6, // 平衡聯想廣度與穩定性
                        'num_predict' => 60
                    ]
                ]);

                if ($response->successful()) {
                    $aiText = trim($response->json('response', ''));
                    // 清理並拆解 AI 產生的聯想關鍵字
                    $aiKeywords = array_map('trim', explode(',', str_replace(['，', '\n', '：'], [',', '', ''], $aiText)));
                    $keywords = array_merge($keywords, $aiKeywords);
                }
            } catch (\Exception $e) {
                Log::warning('Ollama 聯想運算逾時，自動切換至純關鍵字搜尋: ' . $e->getMessage());
            }

            // 過濾掉重複與空白的關鍵字
            $keywords = array_unique(array_filter($keywords));

            // 🔍 資料庫多欄位廣域模糊比對：只要有命中任一關鍵字就會被篩選出來
            $matchedItems = Item::where(function ($query) use ($keywords, $userDescription) {
                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if ($cleanKeyword !== '') {
                        $query->orWhere('title', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('category', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('description', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('location', 'LIKE', "%{$cleanKeyword}%");
                    }
                }
                
                // 額外保險：將使用者的原始描述也拿去對地點或描述做一次比對
                $query->orWhere('location', 'LIKE', "%{$userDescription}%")
                      ->orWhere('description', 'LIKE', "%{$userDescription}%");
            })
            ->latest('id')
            ->get();

            return response()->json([
                'success' => true,
                'matches' => $matchedItems
            ], 200);

        } catch (\Exception $e) {
            Log::error('AI 智慧聯想失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'AI 比對發生錯誤',
                'matches' => []
            ], 500);
        }
    }
}