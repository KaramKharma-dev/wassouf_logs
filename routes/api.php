<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;
use App\Http\Controllers\Api\InternetTransferController;
use App\Http\Controllers\Api\WishBatchController;

use App\Http\Controllers\Api\DaysDailyController;

Route::prefix('days')->group(function () {
    Route::post('/daily/add', [DaysDailyController::class,'add']);           // تجميع حسب التاريخ
    Route::post('/daily/finalize', [DaysDailyController::class,'finalize']);
    Route::post('/daily/finalize-all', [DaysDailyController::class,'finalizeAll']);
// أثر مالي يدوي
});

Route::post('/usd-transfers', [UsdTransferController::class, 'store']);       // إنشاء طلب من الفرونت (Pending)
Route::post('/hooks/usd-sms',  [UsdSmsHookController::class, 'store']);

Route::post('/internet-sms-callback', [InternetTransferController::class, 'smsCallback']);
Route::post('/internet-transfers', [InternetTransferController::class, 'store']);


Route::post('/wish/usd/batches', [WishBatchController::class, 'storeUsd']); // القديم (USD) → wish_rows_raw
Route::post('/wish/lbp/batches', [WishBatchController::class, 'storeLbp']);
