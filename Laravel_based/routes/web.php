<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'receipts');
Route::view('/docs', 'api');
Route::get('/openapi.json', fn () => response()->file(resource_path('openapi.json')));
