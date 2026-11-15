<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Item;
use Carbon\Carbon;

class ItemController extends Controller
{
    // ✨ 新版 index：具備 Schema 自動建表，並將 date 與 type 設為 nullable
    public function index()
    {
        try {
            if (!Schema::hasTable('items')) {
                Schema::create('items', function ($table) {
                    $table->id();
                    $table->string('title');
                    $table->string('category');
                    $table->text('description')->nullable();
                    $table->string('location')->nullable();
                    $table->date('date')->nullable();     // 確保允許空值
                    $table->string('type')->nullable();     // 確保允許空值
                    $table->timestamps();
                });
            }

            // 直接回傳陣列供前端 .forEach 使用
            return Item::latest('id')->get();

        } catch (\Exception $e) {
            Log::error('載入失物清單失敗: ' . $e->getMessage());
            return response()->json([], 500);
        }
    }

    // ✨ 新版 store：加入 date、type 自動補值，並限制 image 只能是圖片格式
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'category'    => 'required|string|max:100',
                'description' => 'nullable|string',
                'location'    => 'nullable|string|max:255',
                'date'        => 'nullable|date',
                'type'        => 'nullable|string|max:50',
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048', // 🖼️ 限制只能上傳圖片
            ]);

            // 如果前端沒傳 date，自動補上今天日期
            if (empty($validated['date'])) {
                $validated['date'] = Carbon::now()->toDateString();
            }

            // 如果前端沒傳 type，自動補上預設值
            if (empty($validated['type'])) {
                $validated['type'] = 'lost';
            }

            $item = Item::create($validated);

            return response()->json([
                'success' => true,
                'message' => '物品新增成功',
                'data'    => $item
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '只能上傳圖片檔案（支援 jpg, png, webp）',
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

    // 3. 新增失物/招領資料（自動補上 date 與 type 欄位，防止空值報錯）
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title'       => 'required|string|max:255',
                'category'    => 'required|string|max:100',
                'description' => 'nullable|string',
                'location'    => 'nullable|string|max:255',
                'date'        => 'nullable|date',
                'type'        => 'nullable|string|max:50',
                // 🖼️ 新增：限制 image 欄位必須是圖片，且格式限定 jpg, jpeg, png, webp，大小限制 2MB 內
                'image'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            ]);

            // 如果有上傳圖片，進行儲存處理
            if ($request->hasFile('image')) {
                $path = $request->file('image')->store('items', 'public');
                $validated['image_path'] = $path; // 假設你的資料庫欄位叫 image_path
            }

            if (empty($validated['date'])) {
                $validated['date'] = Carbon::now()->toDateString();
            }

            if (empty($validated['type'])) {
                $validated['type'] = 'lost';
            }

            $item = Item::create($validated);

            return response()->json([
                'success' => true,
                'message' => '物品新增成功（含圖片）',
                'data'    => $item
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => '只能上傳圖片檔案（支援 jpg, png, webp）',
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
                'date'        => 'nullable|date',
                'type'        => 'nullable|string|max:50',
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