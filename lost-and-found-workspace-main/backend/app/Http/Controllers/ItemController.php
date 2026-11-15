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
        $userDescription = trim($request->input('userDescription'));

        if (!$userDescription || mb_strlen($userDescription) < 2) {
            return response()->json([
                'success' => true,
                'matches' => []
            ]);
        }

        // 1. 豐富版校園互通式同義詞對照表（安全網）
        $synonymsMap = [
            // --- 🪪 校園證件與卡片類（完全互通） ---
            '學生證' => ['學生證', '校園卡', '悠遊卡', '一卡通', 'icash', '愛金卡', '交通卡', '證件套', '卡套', '學生證套', '圖書館借書證'],
            '悠遊卡' => ['學生證', '校園卡', '悠遊卡', '一卡通', 'icash', '愛金卡', '交通卡', '證件套', '卡套'],
            '一卡通' => ['學生證', '校園卡', '悠遊卡', '一卡通', 'icash', '愛金卡', '交通卡', '證件套', '卡套'],
            '證件'   => ['身分證', '健保卡', '駕照', '學生證', '護照', '居留證', '識別證', '證件套', '卡套'],

            // --- 💳 金融與卡片類（完全互通） ---
            '提款卡' => ['提款卡', '金融卡', '信用卡', '簽帳卡', 'Visa', 'Mastercard', '卡片', '提款卡/信用卡'],
            '金融卡' => ['提款卡', '金融卡', '信用卡', '簽帳卡', 'Visa', 'Mastercard', '卡片'],
            '信用卡' => ['提款卡', '金融卡', '信用卡', '簽帳卡', 'Visa', 'Mastercard', '卡片'],

            // --- 👜 錢包與皮夾類（完全互通） ---
            '錢包'   => ['錢包', '皮夾', '短夾', '長夾', '零錢包', '卡夾', '名片夾', '鈔票', '現金'],
            '皮夾'   => ['錢包', '皮夾', '短夾', '長夾', '零錢包', '卡夾', '名片夾'],

            // --- 🔑 宿舍與門禁鑰匙類（完全互通） ---
            '鑰匙'   => ['鑰匙', '機車鑰匙', '汽車鑰匙', '家裡鑰匙', '宿舍鑰匙', '磁扣', '感應扣', '門禁卡', '鑰匙圈', 'AirTag', '逼卡'],
            '磁扣'   => ['磁扣', '感應扣', '門禁卡', '宿舍鑰匙', '鑰匙', '鑰匙圈', '電梯扣', '逼卡'],

            // --- 📱 手機與 3C 類（完全互通） ---
            '手機'   => ['手機', '智慧型手機', '行動電話', 'iPhone', 'iPhone 18', 'iPhone 17', 'iPhone 16', 'Samsung', '三星', 'Pixel', '小米', 'OPPO', 'Sony', '保護殼', '手機殼', '手機架'],
            '智慧型手機' => ['手機', '智慧型手機', '行動電話', 'iPhone', 'Samsung', '三星', 'Pixel', '小米', 'OPPO', 'Sony', '保護殼', '手機殼'],
            '耳機'   => ['耳機', '藍牙耳機', '無線耳機', '入耳式耳機', '耳罩式耳機', 'AirPods', 'AirPods Pro', 'Sony', 'Bose', '耳機殼', '充電盒'],
            '筆電'   => ['筆電', '筆記型電腦', '電腦', 'MacBook', 'Mac', 'ASUS', '華碩', 'MSI', '微星', 'Acer', '宏碁', 'Lenovo', '聯想', 'HP', 'Dell', '筆電包'],
            '平板'   => ['平板', 'iPad', 'iPad Pro', 'iPad Air', 'iPad mini', 'Galaxy Tab', '觸控筆', 'Apple Pencil', '手寫筆'],
            '手錶'   => ['手錶', '智慧手錶', 'Apple Watch', '小米手環', 'Garmin', '電子錶', '機械錶', '手環'],

            // --- 🔌 充電與儲存類（完全互通） ---
            '充電線' => ['充電線', '傳輸線', '線', 'Type-C', 'USB-C', 'Lightning', '快充線', '蘋果線', '安卓線'],
            '充電器' => ['充電器', '豆腐頭', '快充頭', '變壓器', '插頭', '充電頭', 'GaN', '氮化鎵', '充電座'],
            '行動電源' => ['行動電源', '行充', '尿袋', '快充', '無線充電盤', 'MagSafe', '小米行充'],
            '隨身碟' => ['隨身碟', 'USB', '大拇哥', '記憶卡', 'SD卡', '外接硬碟', '硬碟', 'SSD', '行動硬碟'],

            // --- 📚 上課文具與書籍類（完全互通） ---
            '鉛筆盒' => ['鉛筆盒', '筆袋', '文具袋', '原子筆', '自動筆', '立可帶', '橡皮擦', '筆', '螢光筆'],
            '計算機' => ['計算機', '工程計算機', 'Casio', '卡西歐', '財務計算機'],
            '書'     => ['書', '課本', '原文書', '筆記本', '講義', '參考書', '小說', '資料夾', '筆記', '夾子'],

            // --- 💧 隨身日常與雨具類（完全互通） ---
            '水壺'   => ['水壺', '水瓶', '保溫瓶', '保溫杯', '水杯', '環保杯', '冰霸杯', '搖搖杯', '象印', '膳魔師', '太和工房'],
            '雨傘'   => ['雨傘', '摺疊傘', '折疊傘', '長柄傘', '自動傘', '陽傘', '雨具', '雨衣'],
            '眼鏡'   => ['眼鏡', '太陽眼鏡', '墨鏡', '近視眼鏡', '眼鏡盒', '隱形眼鏡', '鏡框', '老花眼鏡'],
            '包包'   => ['包包', '後背包', '側背包', '斜背包', '手提袋', '帆布袋', '電腦包', '化妝包', '書包'],

            // --- 👕 服飾與體育用品類（完全互通） ---
            '外套'   => ['外套', '風衣', '羽絨衣', '夾克', '連帽外套', '帽T', '針織衫', '防曬外套', '衣服', '系服', '制服'],
            '帽子'   => ['帽子', '鴨舌帽', '老帽', '漁夫帽', '毛帽', '安全帽', '球帽'],
            '安全帽' => ['安全帽', '全罩式', '半罩式', '瓜皮帽', '騎士帽', '鏡片', '安全帽鏡片'],
            '鞋子'   => ['鞋子', '球鞋', '運動鞋', '拖鞋', '涼鞋', '帆布鞋', '皮鞋', '跑鞋'],
            '羽球拍' => ['羽球拍', '球拍', '羽球', '羽球具', '網球拍'],
        ];

        // 2. 先從本地安全網查出對應關鍵字
        $keywords = [$userDescription];
        foreach ($synonymsMap as $key => $synonyms) {
            if (mb_stripos($userDescription, $key) !== false || $userDescription === $key) {
                $keywords = array_merge($keywords, $synonyms);
            } else {
                foreach ($synonyms as $syn) {
                    if (mb_stripos($userDescription, $syn) !== false) {
                        $keywords = array_merge($keywords, [$key], $synonyms);
                        break;
                    }
                }
            }
        }

        // 3. 嘗試呼叫 Ollama 進行深度語意擴充（8秒 Timeout 保護）
        $ollamaUrl = env('OLLAMA_URL', 'https://tapping-carol-engaging.ngrok-free.dev');
        
        try {
            $prompt = "你是一個失物招領 AI 助理。請針對使用者的描述「{$userDescription}」，萃取出 3 到 5 個相關的物品關鍵字或同義詞。請「絕對不要」講任何廢話，只要輸出用逗號分隔的關鍵字即可。";

            $response = Http::timeout(8)->post("{$ollamaUrl}/api/generate", [
                'model' => 'llama3:latest',
                'prompt' => $prompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.1,
                    'num_predict' => 50
                ]
            ]);

            if ($response->successful()) {
                $aiText = trim($response->json('response', ''));
                $aiKeywords = array_map('trim', explode(',', str_replace('，', ',', $aiText)));
                $keywords = array_merge($keywords, $aiKeywords);
            }
        } catch (\Exception $e) {
            Log::warning('Ollama 逾時或未回應，自動改用本地安全網搜尋: ' . $e->getMessage());
        }

        // 去除重複關鍵字
        $keywords = array_unique(array_filter($keywords));

        try {
            // 4. 進行資料庫模糊查詢
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
            Log::error('Search Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '查詢失敗詳情：' . $e->getMessage()
            ], 500);
        }
    }
}