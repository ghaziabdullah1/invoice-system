<?php

use App\Http\Controllers\MySupportTicketController;
use Illuminate\Support\Facades\Route;

// البادئة api/support والـ middleware تأتي من BroadcastServiceProvider
Route::get('my-tickets', [MySupportTicketController::class, 'index']);
Route::get('my-tickets/{id}', [MySupportTicketController::class, 'show'])->whereNumber('id');
Route::post('my-tickets/{id}/replies', [MySupportTicketController::class, 'reply'])->whereNumber('id');
