<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\FlowController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);
    Route::post('/logout', [AdminAuthController::class, 'logout'])->middleware('auth');
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::get('/csrf-token', [AdminAuthController::class, 'csrfToken']);
});

Route::prefix('admin-api')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/questionnaires', [FlowController::class, 'questionnaires']);
    Route::post('/questionnaires', [FlowController::class, 'createQuestionnaire']);
    Route::put('/questionnaires/{id}/activate', [FlowController::class, 'activateQuestionnaire']);

    Route::get('/flow', [FlowController::class, 'adminData']);
    Route::get('/sessions/{id}/answers', [FlowController::class, 'sessionAnswers']);

    Route::post('/questions', [FlowController::class, 'addQuestion']);
    Route::put('/questions/{id}', [FlowController::class, 'updateQuestion']);
    Route::delete('/questions/{id}', [FlowController::class, 'deleteQuestion']);
    Route::put('/questions/{id}/position', [FlowController::class, 'saveQuestionPosition']);

    Route::post('/options', [FlowController::class, 'addOption']);
    Route::put('/options/{id}', [FlowController::class, 'updateOption']);
    Route::delete('/options/{id}', [FlowController::class, 'deleteOption']);

    Route::post('/routes', [FlowController::class, 'saveRoute']);
    Route::put('/routes/{id}', [FlowController::class, 'updateRoute']);
    Route::delete('/routes/{id}', [FlowController::class, 'deleteRoute']);
});

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api|storage|auth|admin-api).*$');
