<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    // 取得所有物品清單
    public function index()
    {
        $items = Item::orderBy('created_at', 'desc')->get();
        return response()->json($items);
    }

    // 新增刊登物品
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'    => 'required|string|max:255',
            'category' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'date'     => 'required',
            'type'     => 'required|in:found,lost',
            'image'    => 'nullable|string',
        ]);

        $item = Item::create($validated);

        return response()->json([
            'message' => '刊登成功！',
            'item'    => $item
        ], 201);
    }

    // AI 智慧比對功能 👈 新增在這裡
    public function aiMatch(Request $request)
    {
        $query = $request->input('query'); // 取得前端傳過來的特徵描述，例如 "線性軸"

        if (!$query) {
            return response()->json([]);
        }

        $items = Item::all();

        // 建立同義詞／關聯字典（你可以隨時在這裡新增更多校園常見失物關鍵字）
        $synonymsMap = [
    // --- 鍵盤與電腦周邊 ---
    '線性軸' => ['磁軸', '鍵盤', '機械鍵盤', '軸', '電競', '紅軸', '茶軸', '青軸', '鍵帽'],
    '軸'     => ['鍵盤', '磁軸', '機械', '紅軸', '茶軸', '青軸', '軸體'],
    '鍵盤'   => ['機械鍵盤', '磁軸', '線性軸', '無線鍵盤', '藍牙鍵盤', '羅技', 'Keychron', '雷蛇'],
    '滑鼠'   => ['無線滑鼠', '電競滑鼠', '藍牙滑鼠', '羅技', 'Logitech', '雷蛇', 'Razer', '接收器'],
    '筆電'   => ['筆記型電腦', 'MacBook', 'Mac', 'ASUS', '華碩', 'MSI', '微星', 'Acer', '宏碁', 'Lenovo', '聯想', 'HP', 'Dell', '電腦'],
    '平板'   => ['iPad', 'iPad Pro', 'iPad Air', 'Galaxy Tab', '觸控筆', 'Apple Pencil', '手寫筆'],
    '觸控筆' => ['Apple Pencil', '手寫筆', '電容筆', 'iPad', '平板'],

    // --- 手機與穿戴裝置 ---
    '手機'   => ['iPhone', 'Samsung', '三星', 'Pixel', '小米', 'OPPO', 'Sony', '智慧型手機', '保護殼', '手機殼'],
    '耳機'   => ['AirPods', 'AirPods Pro', '藍牙耳機', '無線耳機', '耳罩式耳機', 'headset', 'Sony', 'Bose', '耳機殼'],
    '手錶'   => ['Apple Watch', '智慧手錶', '小米手環', 'Garmin', '電子錶', '機械錶', '手環'],

    // --- 充電與儲存設備 ---
    '充電線' => ['Type-C', 'USB-C', 'Lightning', '傳輸線', '線', '快充線', '蘋果線'],
    '充電器' => ['豆腐頭', '快充頭', '變壓器', '插頭', '充電頭', 'GaN', '氮化鎵'],
    '行動電源' => ['行充', '尿袋', '快充', '無線充電盤', 'MagSafe', '小米行充'],
    '隨身碟' => ['USB', '大拇哥', '記憶卡', 'SD卡', '外接硬碟', '硬碟', 'SSD'],

    // --- 錢包與證件卡片（失物招領最高頻率） ---
    '錢包'   => ['皮夾', '短夾', '長夾', '零錢包', '卡夾', '名片夾', '鈔票', '現金'],
    '皮夾'   => ['錢包', '短夾', '長夾', '零錢包', '卡夾'],
    '證件'   => ['身分證', '健保卡', '駕照', '學生證', '護照', '居留證', '識別證', '證件套'],
    '學生證' => ['校園卡', '證件', '悠遊卡', '一卡通', '證件套', '卡套'],
    '悠遊卡' => ['一卡通', 'icash', '交通卡', '學生證', '卡套', '卡片'],
    '信用卡' => ['提款卡', '金融卡', '簽帳卡', 'Visa', 'Mastercard', '卡片'],
    '鑰匙'   => ['機車鑰匙', '汽車鑰匙', '家裡鑰匙', '磁扣', '感應扣', '門禁卡', '鑰匙圈', 'AirTag'],
    '磁扣'   => ['感應扣', '門禁卡', '鑰匙', '鑰匙圈', '電梯扣'],

    // --- 隨身日常與雨具 ---
    '水壺'   => ['保溫瓶', '保溫杯', '水杯', '環保杯', '冰霸杯', '搖搖杯', '象印', '膳魔師', '太和工房'],
    '雨傘'   => ['摺疊傘', '折疊傘', '長柄傘', '自動傘', '陽傘', '雨具'],
    '眼鏡'   => ['太陽眼鏡', '墨鏡', '近視眼鏡', '眼鏡盒', '隱形眼鏡', '鏡框'],
    '包包'   => ['後背包', '側背包', '斜背包', '手提袋', '帆布袋', '電腦包', '化妝包', '鉛筆盒', '筆袋'],

    // --- 文具與書籍（校園/辦公常見） ---
    '鉛筆盒' => ['筆袋', '文具袋', '原子筆', '立可帶', '橡皮擦', '計算機'],
    '計算機' => ['工程計算機', 'Casio', '卡西歐', '財務計算機'],
    '書'     => ['課本', '原文書', '筆記本', '講義', '參考書', '小說', '資料夾'],

    // --- 衣著配件 ---
    '外套'   => ['風衣', '羽絨衣', '夾克', '連帽外套', '帽T', '針織衫', '防曬外套', '衣服'],
    '帽子'   => ['鴨舌帽', '老帽', '漁夫帽', '毛帽', '安全帽'],
    '安全帽' => ['全罩式', '半罩式', '瓜皮帽', '騎士帽', '鏡片'],
];

        // 擴充搜尋關鍵字
        $searchKeywords = [$query];
        foreach ($synonymsMap as $key => $synonyms) {
            if (str_contains($query, $key)) {
                $searchKeywords = array_merge($searchKeywords, $synonyms);
            }
        }

        // 進行比對過濾
        $matchedItems = $items->filter(function ($item) use ($query, $searchKeywords) {
            $title = strtolower($item->title ?? '');
            $category = strtolower($item->category ?? '');
            $location = strtolower($item->location ?? '');

            // 檢查是否直接命中原關鍵字
            if (str_contains($title, strtolower($query)) || 
                str_contains($category, strtolower($query)) || 
                str_contains($location, strtolower($query))) {
                return true;
            }

            // 檢查是否命中關聯同義詞（例如輸入「線性軸」對應到「磁軸鍵盤」）
            foreach ($searchKeywords as $word) {
                if (str_contains($title, strtolower($word)) || 
                    str_contains($category, strtolower($word))) {
                    return true;
                }
            }

            return false;
        });

        return response()->json($matchedItems->values());
    }

    // 刪除指定 ID 的物品
    public function destroy($id)
    {
        $item = Item::find($id);

        if (!$item) {
            return response()->json([
                'message' => '找不到該筆資料！'
            ], 404);
        }

        $item->delete();

        return response()->json([
            'message' => '刪除成功！'
        ]);
    }
}