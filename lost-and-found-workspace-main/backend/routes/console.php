<?php

use App\Http\Controllers\ItemController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('claims:expire-overdue', function () {
    $expiredReviews = ItemController::expireOverdueManualReviews();
    $purgedCompleted = ItemController::purgeExpiredCompletedItems();
    $this->info("已自動重置 {$expiredReviews} 筆逾期人工審核，並清除 {$purgedCompleted} 筆超過 3 個月之已結案失物。");
})->purpose('自動重置逾期人工審核並清除保留滿 3 個月的已結案失物');

Schedule::command('claims:expire-overdue')->hourly();
