<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Item;

class ItemController extends Controller
{
    // 1. 取得所有失物清單（直接回傳陣列，徹底解決 frontend .forEach 崩潰）
    public function index()
    {
        try {
            // 🛡️ 自動檢查：如果 items 這張表不存在，就自動建立它
            if (!Schema::hasTable('items')) {
                Schema::create('items', function ($table) {
                    $table->id();
                    $table->string('title');
                    $table->string('category');
                    $table->text('description')->nullable();
                    $table->string('location')->nullable();
                    $table->timestamps();
                });
            }

            // 直接回傳陣列本身，讓前端的 .forEach 可以直接讀取
            return Item::latest('id')->get();

        } catch (\Exception $e) {
            Log::error('載入失物清單失敗: ' . $e->getMessage());
            // 發生例外時回傳空陣列，避免前端直接炸掉
            return response()->json([], 500);
        }
    }

    // 2. 取得單一物品詳細資料
    public function show($id)
    {
        try {
            $item = Item::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $item
            ], 200);

        } catch (\Exception $e) {
            Log::error('取得單一物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage()
            ], 500);
        }
    }

    // 3. 新增失物/招領資料
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'category'    => 'required|string|max:100',
                'description' => 'nullable|string',
                'location'    => 'nullable|string|max:255',
            ]);

            $item = Item::create($validated);

            return response()->json([
                'success' => true,
                'message' => '物品新增成功',
                'data'    => $item
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '資料驗證失敗',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('新增物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage()
            ], 500);
        }
    }

    // 4. 編輯修改指定 ID 的物品資料
    public function update(Request $request, $id)
    {
        try {
            $item = Item::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料，無法修改'
                ], 404);
            }

            $validated = $request->validate([
                'title'       => 'sometimes|required|string|max:255',
                'category'    => 'sometimes|required|string|max:100',
                'description' => 'nullable|string',
                'location'    => 'nullable|string|max:255',
            ]);

            $item->update($validated);

            return response()->json([
                'success' => true,
                'message' => '物品資料更新成功',
                'data'    => $item
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '資料驗證失敗',
                'errors'  => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            Log::error('更新物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage()
            ], 500);
        }
    }

    // 5. 刪除指定 ID 的物品
    public function destroy($id)
    {
        try {
            $item = Item::find($id);

            if (!$item) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該筆物品資料'
                ], 404);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => '物品刪除成功'
            ], 200);

        } catch (\Exception $e) {
            Log::error('刪除物品失敗: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => '伺服器錯誤：' . $e->getMessage()
            ], 500);
        }
    }
}