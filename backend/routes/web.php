<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Swagger UI (static assets live in public/docs).
Route::redirect('/docs', '/docs/index.html');
