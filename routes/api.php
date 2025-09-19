<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;
use App\Http\Controllers\Api\InternetTransferController;
use App\Http\Controllers\Api\WishBatchController;


Route::post('/usd-transfers', [UsdTransferController::class, 'store']);       // إنشاء طلب من الفرونت (Pending)
Route::post('/hooks/usd-sms',  [UsdSmsHookController::class, 'store']);

Route::post('/internet-sms-callback', [InternetTransferController::class, 'smsCallback']);
Route::post('/internet-transfers', [InternetTransferController::class, 'store']);


Route::post('/wish/batches', [WishBatchController::class, 'store']); // رفع وتحليل

