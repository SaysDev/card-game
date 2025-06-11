<?php // WebSocket Authentication Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/api/ws/token', [WebSocketAuthController::class, 'getToken']);
    Route::post('/api/ws/token/refresh', [WebSocketAuthController::class, 'refreshToken']);
}); 