<?php

use App\Http\Controllers\FlowController;
use Illuminate\Support\Facades\Route;

Route::post('/flow/start', [FlowController::class, 'start']);
Route::post('/flow/answer', [FlowController::class, 'answer']);
Route::post('/flow/lead', [FlowController::class, 'saveLead']);
Route::post('/flow/prune', [FlowController::class, 'pruneAnswers']);
