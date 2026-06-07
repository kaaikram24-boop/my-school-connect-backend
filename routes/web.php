<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Add this dummy route to prevent the error
Route::match(['get', 'post'], '/login', function () {
    return response()->json(['message' => 'Please use /api/v1/auth/login for API authentication'], 401);
})->name('login');

// Add a fallback for any web route
Route::fallback(function () {
    return response()->json(['message' => 'Route not found. Please check the API documentation.'], 404);
});

// ==============================================
// TES ROUTES AJOUTÉES
// ==============================================

// Route de test pour la racine (alternative à welcome)
Route::get('/hello', function () {
    return 'Hello from Laravel on Render!';
});

// Route API de test
Route::get('/api/test', function () {
    return response()->json([
        'message' => 'API is working!',
        'status' => 'success',
        'timestamp' => now()
    ]);
});