<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::view('/', 'app');
Route::view('/games/{game}', 'app')->where('game', '[a-f0-9]{32}');
