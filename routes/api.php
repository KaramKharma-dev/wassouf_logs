<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;
use App\Http\Controllers\Api\InternetTransferController;
use App\Http\Controllers\Api\WishBatchController;
use App\Http\Controllers\Api\WishRowsProcessController;

Route::get('/wish/process', [WishRowsProcessController::class, 'index'])->name('wish.process.index');
Route::post('/wish/process', [WishRowsProcessController::class, 'run'])->name('wish.process.run');



Route::post('/usd-transfers', [UsdTransferController::class, 'store']);       // إنشاء طلب من الفرونت (Pending)
Route::post('/hooks/usd-sms',  [UsdSmsHookController::class, 'store']);

Route::post('/internet-sms-callback', [InternetTransferController::class, 'smsCallback']);
Route::post('/internet-transfers', [InternetTransferController::class, 'store']);


Route::post('/wish/usd/batches', [WishBatchController::class, 'storeUsd']); // القديم (USD) → wish_rows_raw
Route::post('/wish/lbp/batches', [WishBatchController::class, 'storeLbp']);
