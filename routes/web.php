<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\WishRowsProcessController;

Route::get('/wish/process', [WishRowsProcessController::class, 'index'])->name('wish.process.index');
Route::post('/wish/process', [WishRowsProcessController::class, 'run'])->name('wish.process.run');


Route::get('/', function () {
    return view('welcome');
});
