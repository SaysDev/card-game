<?php

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('can create a game', function () {
    $game = Game::create([
        'name' => 'Test Game',
        'status' => 'waiting',
        'max_players' => 4,
        'current_players' => 1,
        'game_data' => ['deck' => ['card1', 'card2']]
    ]);

    expect($game)->toBeInstanceOf(Game::class)
        ->and($game->name)->toBe('Test Game')
        ->and($game->status)->toBe('waiting')
        ->and($game->max_players)->toBe(4)
        ->and($game->current_players)->toBe(1)
        ->and($game->game_data)->toBe(['deck' => ['card1', 'card2']]);
});

test('waiting scope returns only waiting games', function () {
    // Create a waiting game
    Game::create([
        'name' => 'Waiting Game',
        'status' => 'waiting',
        'max_players' => 4,
        'current_players' => 1
    ]);

    // Create an active game
    Game::create([
        'name' => 'Active Game',
        'status' => 'playing',
        'max_players' => 4,
        'current_players' => 4
    ]);

    $waitingGames = Game::waiting()->get();

    expect($waitingGames)->toHaveCount(1)
        ->and($waitingGames->first()->name)->toBe('Waiting Game');
});

test('active scope returns only active games', function () {
    // Create a waiting game
    Game::create([
        'name' => 'Waiting Game',
        'status' => 'waiting',
        'max_players' => 4,
        'current_players' => 1
    ]);

    // Create an active game
    Game::create([
        'name' => 'Active Game',
        'status' => 'playing',
        'max_players' => 4,
        'current_players' => 4
    ]);

    $activeGames = Game::active()->get();

    expect($activeGames)->toHaveCount(1)
        ->and($activeGames->first()->name)->toBe('Active Game');
});
