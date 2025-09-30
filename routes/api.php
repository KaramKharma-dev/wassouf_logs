<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;
use App\Http\Controllers\Api\InternetTransferController;
use App\Http\Controllers\Api\WishBatchController;

use App\Http\Controllers\Api\DaysDailyController;

Route::prefix('days')->group(function () {
    Route::post('/daily/add',        [\App\Http\Controllers\Api\DaysDailyController::class, 'add']);        // إدخال يدوي
    Route::post('/daily/ingest-sms', [\App\Http\Controllers\Api\DaysDailyController::class, 'ingestSms']);   // إدخال من SMS
    Route::post('/daily/finalize',   [\App\Http\Controllers\Api\DaysDailyController::class, 'finalize']);
    Route::post('/daily/finalize-all', [\App\Http\Controllers\Api\DaysDailyController::class, 'finalizeAll']);
});

Route::post('/usd-transfers', [UsdTransferController::class, 'store']);       // إنشاء طلب من الفرونت (Pending)
Route::post('/hooks/usd-sms',  [UsdSmsHookController::class, 'store']);

Route::post('/internet-sms-callback', [InternetTransferController::class, 'smsCallback']);
Route::post('/internet-transfers', [InternetTransferController::class, 'store']);


Route::post('/wish/usd/batches', [WishBatchController::class, 'storeUsd']); // القديم (USD) → wish_rows_raw
Route::post('/wish/lbp/batches', [WishBatchController::class, 'storeLbp']);
Route::post('/wish/pc/batches', [WishBatchController::class, 'storePc'])->name('api.wish.pc.batches');
Route::post('/wish/pc/lb/batches', [WishBatchController::class, 'storePclb'])->name('api.wish.pc.batches');

