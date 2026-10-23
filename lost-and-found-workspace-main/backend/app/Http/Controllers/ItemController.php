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

    // 刪除指定 ID 的物品 👈 加在這邊
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