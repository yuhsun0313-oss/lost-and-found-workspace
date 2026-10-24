<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Item; // 假設你的物品 Model 名稱為 Item

class AiController extends Controller
{
    public function matchItems(Request $request)
    {
        $userDescription = $request->input('userDescription');

        if (!$userDescription) {
            return response()->json(['success' => false, 'message' => '請提供物品描述'], 400);
        }

        // 1. 從資料庫取得所有物品資料
        $items = Item::all(['id', 'title', 'category', 'location', 'date']);

        // 2. 組合給 Ollama 的 Prompt
        $prompt = "你是一個失物招領 AI 助手。使用者遺失了：\"{$userDescription}\"\n";
        $prompt .= "以下是目前資料庫中的失物清單：\n" . json_encode($items, JSON_UNESCAPED_UNICODE) . "\n";
        $prompt .= "請分析使用者的描述，找出最可能相符的前 3 筆物品。你必須嚴格只回傳一個包含相符物品 ID 的 JSON 陣列，例如：[\"1\", \"3\"]，不要包含任何其他說明文字。";

        try {
            // 3. 使用 Laravel 的 Http Facade 發送請求給本機的 Ollama
            $response = Http::timeout(60)->post('http://127.0.0.1:11434/api/generate', [
                'model' => 'llama3', // 請確認與你本地下載的模型名稱一致
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json'
            ]);

            if ($response->successful()) {
                $aiData = $response->json();
                $matchedIds = json_decode($aiData['response'] ?? '[]', true);

                // 4. 根據 AI 回傳的 ID 陣列過濾出對應的物品
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