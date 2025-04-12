<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('ai-assistant');
});

// Add this if you want a specific route for the AI assistant
Route::get('/ai-assistant', function () {
    return view('ai-assistant');
});


Route::get('/info', function () {
    phpinfo();
});