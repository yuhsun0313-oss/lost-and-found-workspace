<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Item;

class AiController extends Controller
{
    public function matchItems(Request $request)
    {
        $userDescription = $request->input('userDescription');

        if (!$userDescription) {
            return response()->json(['success' => false, 'message' => '請提供物品描述'], 400);
        }

        // 💡 1. 必須先從資料庫取得物品清單（請根據你的 Model 名稱調整，例如 Item::all() 或 LostItem::all()）
        $items = \App\Models\Item::all(); 

        // 2. 將物品清單轉成乾淨的 JSON 字串
        $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

        // 3. 組合給 Ollama 的 Prompt
        $prompt = "你是一個專業的失物招領比對助手。
                   請比對【使用者描述】與【資料庫物品清單】，找出高度相似或可能的物品。

                  【比對規則】：
                   1. 顏色、品牌、物品類型若吻合，優先列入。
                   2. 若沒有任何物品符合描述，請回傳空陣列 `[]`，絕不可胡亂推薦。
                   3. 嚴禁輸出任何解釋、開場白或結尾，只能輸出標準 JSON 格式。

                  【使用者描述】：{$userDescription}

                  【資料庫物品清單】：
                   {$itemsJson}

                   請輸出格式如下：
                   {\"ids\": [\"1\", \"2\"]}";

        try {
            // 3. 使用 Laravel 的 Http Facade 發送請求給本機的 Ollama[cite: 3]
            $response = Http::timeout(60)->post('http://127.0.0.1:11434/api/generate', [
                'model' => 'llama3', // 請確認與你本地下載的模型名稱一致
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json'
            ]);

            // 記錄 Ollama 回傳的原始資料供除錯用[cite: 3]
            \Log::info('Ollama Response:', ['response' => $response->json()]);

            if ($response->successful()) {
                $aiData = $response->json();
                $rawContent = $aiData['response'] ?? '[]';
                
                // 將 Ollama 回傳的字串解碼[cite: 3]
                $decoded = json_decode($rawContent, true);

                // 兼容處理：同時支援 {"ids": ["4"]} 與 ["4"] 格式
                $matchedIds = [];
                if (isset($decoded['ids']) && is_array($decoded['ids'])) {
                    $matchedIds = $decoded['ids'];
                } elseif (is_array($decoded)) {
                    $matchedIds = $decoded;
                }

                // 4. 根據 AI 回傳的 ID 陣列過濾出對應的物品[cite: 3]
                $matchedItems = $items->filter(function ($item) use ($matchedIds) {
                    return in_array((string)$item->id, $matchedIds);
                })->values();

                return response()->json([
                    'success' => true,
                    'matches' => $matchedItems
                ]);
            }

            return response()->json(['success' => false, 'message' => 'AI 服務無回應'], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => '無法連線至 Ollama 本機服務：' . $e->getMessage()
            ], 500);
        }
    }
}