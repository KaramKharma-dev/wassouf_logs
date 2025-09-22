<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;
use App\Http\Controllers\Api\InternetTransferController;
use App\Http\Controllers\Api\WishBatchController;
// routes/api.php
Route::get('/health', fn() => response()->json(['ok'=>true]));
// routes/api.php
Route::post('/days/close-sessions', function (\Illuminate\Http\Request $r, \App\Services\DaysTopupService $svc) {
    abort_unless($r->header('X-Admin-Token') === config('app.days_admin_token', env('DAYS_ADMIN_TOKEN')), 403);
    $n = $svc->closeExpiredSessions();
    return response()->json(['closed'=>$n]);
});

Route::post('/days/usd-ingest', [\App\Http\Controllers\Api\DaysUsdController::class, 'ingest']);


Route::post('/usd-transfers', [UsdTransferController::class, 'store']);       // إنشاء طلب من الفرونت (Pending)
Route::post('/hooks/usd-sms',  [UsdSmsHookController::class, 'store']);

Route::post('/internet-sms-callback', [InternetTransferController::class, 'smsCallback']);
Route::post('/internet-transfers', [InternetTransferController::class, 'store']);


Route::post('/wish/usd/batches', [WishBatchController::class, 'storeUsd']); // القديم (USD) → wish_rows_raw
Route::post('/wish/lbp/batches', [WishBatchController::class, 'storeLbp']);
