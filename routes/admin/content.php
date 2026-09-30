<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\ContentController;

Route::get('/content/privacy', [ContentController::class, 'privacy']);
Route::get('/content/terms', [ContentController::class, 'terms']);
