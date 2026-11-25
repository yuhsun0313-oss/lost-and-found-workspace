<?php

use App\Http\Controllers\ItemController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('claims:expire-overdue', function () {
    $count = ItemController::expireOverdueManualReviews();
    $this->info("已自動重置 {$count} 筆超過 3 天期限的待人工審核失物。");
})->purpose('自動重置超過 3 天人工審核期限的認領失敗次數並寄信通知');

Schedule::command('claims:expire-overdue')->hourly();
