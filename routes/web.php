<?php

use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GameController;
use App\Http\Controllers\WebSocketAuthController;
use App\Http\Controllers\Settings\ProfileController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', function () {
        return Inertia::render('Dashboard');
    })->name('dashboard');

    Route::get('/game', [GameController::class, 'index'])->name('game.index');
    Route::get('/game/{id}', [GameController::class, 'show'])->name('game.show');
});

// Game routes
Route::middleware(['auth'])->group(function () {
    Route::get('/games', [GameController::class, 'index'])->name('games.index');
    Route::get('/games/create', [GameController::class, 'create'])->name('games.create');
    Route::post('/games', [GameController::class, 'store'])->name('games.store');
    Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
    Route::post('/games/{game}/join', [GameController::class, 'join'])->name('games.join');
    Route::post('/games/{game}/leave', [GameController::class, 'leave'])->name('games.leave');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

//
//Route::get('/', function () {
//    return Inertia::render('Welcome');
//})->name('home');
//
//Route::get('dashboard', function () {
//    return Inertia::render('Dashboard');
//})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
//require __DIR__.'/auth.php';

Route::get('/lobby', function () {
    return Inertia::render('Lobby');
})->middleware(['auth']);

// Game table route
Route::get('/game/table', function () {
    return Inertia::render('game/Table');
})->middleware(['auth'])->name('game.table');

Route::get('/menu', function () {
    return Inertia::render('game/Home');
})->name('mobile.home');

Route::get('/events', function () {
    return Inertia::render('game/Events');
})->name('mobile.events');

Route::get('/clubs', function () {
    return Inertia::render('game/Clubs');
})->name('mobile.clubs');

Route::get('/friends', function () {
    return Inertia::render('game/Friends');
})->name('mobile.friends');

Route::get('/shop', function () {
    return Inertia::render('game/Shop');
})->name('mobile.shop');

Route::middleware('auth')->group(function () {
    Route::get('/api/ws/token', [WebSocketAuthController::class, 'getToken']);
    Route::post('/api/ws/token/refresh', [WebSocketAuthController::class, 'refreshToken']);
}); 