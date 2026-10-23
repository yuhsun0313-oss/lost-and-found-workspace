<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    // 💡 加上這行，允許以下欄位進行寫入
    protected $fillable = [
        'title',
        'category',
        'location',
        'date',
        'type',
        'image',
    ];
}