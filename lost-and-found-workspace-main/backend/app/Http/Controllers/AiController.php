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
        $ollamaUrl = "https://tapping-carol-engaging.ngrok-free.dev/api/generate";
        $userDescription = trim($request->input('userDescription'));
        // 🛡️ 防呆機制：若輸入為空、字數過少或全為特殊符號，直接攔截回傳空陣列
        if (!$userDescription || mb_strlen($userDescription) < 2 || preg_match('/^[^\w\s]+$/u', $userDescription)) {
            return response()->json([
                'success' => true,
                'matches' => []
            ]);
        }

        // 步驟 1：建構全能通用的語意擴充與同義詞提示詞 (Prompt)
        $prompt = "你是一個校園失物招領系統的智慧語意檢索與同義詞擴充專家。請分析使用者輸入的任意物品描述。

        【核心運作規則】：
            1. 意圖過濾：若輸入內容屬於日常問候、情緒抒發或與失物招領無關的言論，請回傳：{\"keywords\": []}。
            2. 錯別字與諧音糾正：自動糾正錯別字、注音或諧音（例如「原資比」->「原子筆」、「保憲套」->「保險套」）。
            3. 全方位同義詞與俗稱擴充（Universal Synonyms & Aliases）：無論使用者輸入什麼物品，你必須主動聯想並列出該物品的所有常見同義詞、台語/口語俗稱、英文簡稱、正式名稱以及相關的上位/下位概念。例如：
            - 輸入「手機」或「行動電話」 -> 必須擴充出彼此以及「智慧型手機」、「iPhone」、「Android」、「手機」等。
            - 輸入「水壺」 -> 必須擴充出「水杯」、「水瓶」、「保溫瓶」、「水樽」。
            - 輸入「鑰匙」 -> 必須擴充出「門鑰」、「車鑰匙」、「磁扣」、「感應卡」。
            - 輸入任何物品，皆須舉一反三提供其所有可能的同義詞與替換稱呼。

            請將使用者輸入的原詞、糾正後的詞、以及所有擴充的同義詞與聯想詞全部放入陣列，格式嚴格規定如下：{\"keywords\": [\"原詞\", \"同義詞1\", \"同義詞2\", \"聯想詞3\"]}

            使用者輸入：{$userDescription}
            請嚴格回傳標準 JSON 格式物件（絕對不要有其他多餘文字）：";
        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'ngrok-skip-browser-warning' => 'true', // 👈 這行會直接強制略過 ngrok 的阻擋畫面
                    'User-Agent' => 'Laravel-Client'
                ])
                ->post($ollamaUrl, [
                    'model' => 'llama3:latest',
                    'prompt' => $prompt,
                    'stream' => false,
                    'format' => 'json',
                    'options' => [
                        'temperature' => 0.0,
                    ]
                ]);

            Log::info('Ollama Keyword Inference:', ['response' => $response->json()]);
            $keywords = [];

            if ($response->successful()) {
                $aiData = $response->json();
                $decoded = json_decode($aiData['response'] ?? '{}', true);
                $keywords = $decoded['keywords'] ?? [];
            }

            // 💡 步驟 2：後端備用機制 (Fallback Mechanism)
            // 如果 AI 的過濾過於保守導致回傳空陣列，但使用者確實輸入了合法的具體詞彙（如「保險套」），
            // 系統自動啟動備用防呆，直接將使用者輸入納入查詢，確保 100% 不會被誤殺。
            if (empty($keywords) && mb_strlen($userDescription) >= 2) {
                $stopwords = ['你好', '早安', '哈囉', 'hi', '嗨', '早'];
                if (!in_array(mb_strtolower($userDescription), $stopwords)) {
                    $keywords = [$userDescription];
                }
            }

            if (empty($keywords) || !is_array($keywords)) {
                return response()->json(['success' => true, 'matches' => []]);
            }

            // 步驟 3：交由資料庫進行多條件模糊查詢 (Database Query)，確保精準且不漏接
            $matchedItems = Item::where(function ($query) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $cleanKeyword = trim($keyword);
                    if ($cleanKeyword !== '') {
                        $query->orWhere('title', 'LIKE', "%{$cleanKeyword}%")
                              ->orWhere('category', 'LIKE', "%{$cleanKeyword}%");
                    }
                }
            })
            ->latest('id')
            ->get();

            return response()->json([
                'success' => true,
                'matches' => $matchedItems
            ]);

        } catch (\Exception $e) {
    Log::error('Ollama Error: ' . $e->getMessage()); // 記錄到日誌
    return response()->json([
        'success' => false,
        'message' => 'Ollama 連線失敗詳情：' . $e->getMessage()
    ], 500);
}
    }
}