<?php

use App\Http\Controllers\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::get('/health',[ReceiptController::class,'health']);
Route::post('/receipts',[ReceiptController::class,'upload']);
