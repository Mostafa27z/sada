<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This file loads versioned API route files.
| Each version has its own route file under routes/api/.
|
*/

Route::prefix('v1')->group(base_path('routes/api/v1.php'));
