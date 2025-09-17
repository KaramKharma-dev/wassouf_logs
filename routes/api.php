<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UsdTransferController;
use App\Http\Controllers\Api\UsdSmsHookController;

Route::post('/usd-transfers', [UsdTransferController::class, 'store']);

Route::post('/hooks/usd-sms', [UsdSmsHookController::class, 'store']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
