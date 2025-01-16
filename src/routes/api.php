<?php

use App\Http\Controllers\Api\RsudController;
use App\Http\Controllers\Api\RsudControllerFilter;
use App\Http\Controllers\Api\RsudControllerSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});


Route::get('merge-api-rsud', [RsudController::class, 'index']);
Route::get('merge-api-rsud-summary', [RsudControllerSummary::class, 'index']);
Route::get('merge-api-rsud-filter', [RsudControllerFilter::class, 'index']);
