<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Domains\Http\Controllers\AdminController;

Route::get('/overview', [AdminController::class, 'index']);
Route::post('/test', [AdminController::class, 'test']);
