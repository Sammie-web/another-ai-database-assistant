<?php

use Illuminate\Support\Facades\Route;
use Another\DatabaseAssistant\AssistantController;

Route::middleware(config('assistant.middleware'))->prefix('assistant')->name('assistant.')->group(function () {
    Route::get('/', [AssistantController::class, 'index'])->name('index');
    Route::post('/chat', [AssistantController::class, 'chat'])->middleware('throttle:assistant')->block(180, 5)->name('chat');
    Route::post('/reset', [AssistantController::class, 'reset'])->block(180, 5)->name('reset');
    Route::post('/proposals/{id}/confirm', [AssistantController::class, 'confirm'])->middleware('throttle:assistant')->name('confirm');
});
