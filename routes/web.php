<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\WishRowsProcessController;
use App\Http\Controllers\WishRawAltProcessController;
use App\Http\Controllers\WishRowsPcProcessController;
use App\Http\Controllers\WishRowsPcLbProcessController;

//WISH USD
Route::get('/wish/process', [WishRowsProcessController::class, 'index'])->name('wish.process.index');
Route::post('/wish/process', [WishRowsProcessController::class, 'run'])->name('wish.process.run');

//WISH LBP
Route::get('/wish-alt/process', [WishRawAltProcessController::class, 'index'])->name('wish.alt_process.index');
Route::post('/wish-alt/process/run', [WishRawAltProcessController::class, 'run'])->name('wish.alt_process.run');

//WISH USD PC
Route::get('/wish/pc/process', [WishRowsPcProcessController::class, 'index'])->name('wish.pc.process.index');
Route::post('/wish/pc/process', [WishRowsPcProcessController::class, 'run'])->name('wish.pc.process.run');

// routes/web.php
Route::get('/wish/pc', function () {
    return view('wish.pc_upload');
})->name('wish.pc');

Route::get('/wish/pclb/process', [WishRowsPcLbProcessController::class, 'index'])->name('wish.pclb.process.index');
Route::post('/wish/pclb/process', [WishRowsPcLbProcessController::class, 'run'])->name('wish.pclb.process.run');

Route::get('/', function () {
    return view('welcome');
});

