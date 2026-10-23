<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
{
    Schema::create('items', function (Blueprint $table) {
        $table->id();
        $table->string('title');         // 物品名稱
        $table->string('category');      // 分類
        $table->string('location');      // 地點
        $table->dateTime('date');        // 時間日期
        $table->enum('type', ['found', 'lost']); // 類型：found(已拾獲) 或 lost(尋找中)
        $table->longText('image')->nullable();  // 圖片
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
