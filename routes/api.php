// WebSocket Authentication Routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/ws/token', [WebSocketAuthController::class, 'getToken']);
    Route::post('/ws/token/refresh', [WebSocketAuthController::class, 'refreshToken']);
}); 