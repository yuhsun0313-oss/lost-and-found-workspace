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
            '線性軸' => ['磁軸', '鍵盤', '機械鍵盤', '軸', '電競'],
            '軸'    => ['鍵盤', '磁軸', '機械'],
            '筆電'  => ['筆記型電腦', 'Macbook', 'asus', 'msi', 'acer'],
            '耳機'  => ['AirPods', '藍牙', 'headset', 'airpods'],
            '充電線' => ['Type-C', 'Lightning', '傳輸線', '線'],
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