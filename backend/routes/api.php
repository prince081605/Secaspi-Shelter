<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

require __DIR__ . '/api_public.php';

require __DIR__ . '/auth.php';

// Current authenticated user. Uses the curated AuthController@me shape (id, full_name, email,
// phone, role, email_verified) rather than returning the raw Eloquent model.
Route::get('/user', [AuthController::class, 'me'])->middleware('auth:sanctum');

