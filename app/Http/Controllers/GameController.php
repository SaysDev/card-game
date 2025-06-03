<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GameController extends Controller
{
    /**
     * Display a listing of available games.
     */
    public function index()
    {
        $games = Game::where('status', '!=', 'ended')
                    ->where('current_players', '<', 'max_players')
                    ->orderBy('created_at', 'desc')
                    ->get();

        return inertia('games/Index', [
            'games' => $games
        ]);
    }

    /**
     * Show the form for creating a new game.
     */
    public function create()
    {
        return inertia('games/Create');
    }

    /**
     * Store a newly created game in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:64',
            'max_players' => 'required|integer|min:2|max:8',
        ]);

        $game = Game::create([
            'name' => $validated['name'],
            'status' => 'waiting',
            'max_players' => $validated['max_players'],
            'current_players' => 1,
            'game_data' => [
                'players' => [Auth::id()],
                'deck' => [],
                'current_turn' => -1,
                'game_started' => false,
                'last_updated' => now()->timestamp
            ],
        ]);

        // Add the creator to the game
        $game->users()->attach(Auth::id(), [
            'status' => 'waiting',
            'score' => 0,
            'cards' => json_encode([])
        ]);

        return redirect()->route('games.show', $game)
            ->with('success', 'Game created successfully!');
    }

    /**
     * Display the specified game.
     */
    public function show(Game $game)
    {
        // Load players with their user information
        $game->load(['players.user']);

        return inertia('games/Show', [
            'game' => $game,
            'isPlayer' => $game->players->contains('user_id', Auth::id()),
            'canJoin' => $game->status === 'waiting' && $game->current_players < $game->max_players
        ]);
    }

    /**
     * Join an existing game.
     */
    public function join(Game $game)
    {
        if ($game->status !== 'waiting') {
            return redirect()->back()->with('error', 'This game has already started or ended.');
        }

        if ($game->current_players >= $game->max_players) {
            return redirect()->back()->with('error', 'This game is already full.');
        }

        $user = Auth::user();
        $activeGame = $user->activeGame();

        // Check if player is already in another game
        if ($activeGame && $activeGame->id !== $game->id) {
            return redirect()->back()->with('error', 'You are already in a game. You must leave your current game first.');
        }

        if (!$game->players->contains(Auth::id())) {
            // Add player to the game
            $game->users()->attach(Auth::id(), [
                'status' => 'waiting',
                'score' => 0,
                'cards' => json_encode([])
            ]);

            // Update game data
            $gameData = $game->game_data;
            $gameData['players'][] = Auth::id();

            $game->update([
                'current_players' => $game->current_players + 1,
                'game_data' => $gameData
            ]);
        }

        return redirect()->route('games.show', $game)
            ->with('success', 'You have joined the game!');
    }

    /**
     * Leave a game.
     */
    public function leave(Game $game)
    {
        if ($game->players->contains(Auth::id())) {
            // Update game data
            $gameData = $game->game_data;
            $gameData['players'] = array_values(array_diff($gameData['players'], [Auth::id()]));

            // Detach player
            $game->users()->detach(Auth::id());

            // Update game
            $game->update([
                'current_players' => $game->current_players - 1,
                'game_data' => $gameData
            ]);

            // If no players left, delete the game
            if ($game->current_players <= 0) {
                $game->delete();
                return redirect()->route('games.index')
                    ->with('success', 'Game deleted as no players remain.');
            }
        }

        return redirect()->route('games.index')
            ->with('success', 'You have left the game.');
    }
}
