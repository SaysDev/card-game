<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use App\Servers\Utilities\GameUtilities;
use OpenSwoole\WebSocket\Server;

class GameHandler
{
    private MemoryStorage $storage;

    public function __construct(MemoryStorage $storage)
    {
        $this->storage = $storage;
    }

    public function handleGameAction(Server $server, int $fd, array $data): void
    {
        if (!$this->storage->playerExists($fd)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You must authenticate first'
            ]));
            return;
        }

        $player = $this->storage->getPlayer($fd);
        $roomId = $player['room_id'];

        if (!$roomId || !$this->storage->roomExists($roomId)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'You are not in a game room'
            ]));
            return;
        }

        $room = $this->storage->getRoom($roomId);

        if ($room['status'] !== 'playing') {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'The game has not started yet or has ended'
            ]));
            return;
        }

        $gameData = json_decode($room['game_data'], true);

        // Check if it's this player's turn
        $currentPlayerFd = $gameData['players'][$gameData['current_turn']] ?? null;

        if ($currentPlayerFd != $fd) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'It\'s not your turn'
            ]));
            return;
        }

        // Process the game action
        if (!isset($data['action_type'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Invalid action'
            ]));
            return;
        }

        // Process different game actions based on the card game rules
        switch ($data['action_type']) {
            case 'play_card':
                $this->handlePlayCard($server, $fd, $roomId, $gameData, $data);
                break;

            case 'draw_card':
                $this->handleDrawCard($server, $fd, $roomId, $gameData);
                break;

            case 'pass_turn':
                $this->handlePassTurn($server, $fd, $roomId, $gameData);
                break;

            default:
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'message' => 'Unknown game action: ' . $data['action_type']
                ]));
        }
    }

    public function startGame(Server $server, string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Ensure we have at least 2 players
        if (count($gameData['players']) < 2) {
            return;
        }

        // Shuffle the deck
        shuffle($gameData['deck']);

        // Deal cards to players (e.g., 7 cards each for a standard card game)
        foreach ($gameData['players'] as $playerFd) {
            if (!$this->storage->playerExists($playerFd)) {
                continue;
            }

            $playerCards = [];
            for ($i = 0; $i < 7; $i++) {
                if (empty($gameData['deck'])) {
                    break;
                }
                $playerCards[] = array_pop($gameData['deck']);
            }

            $player = $this->storage->getPlayer($playerFd);
            $this->storage->setPlayer($playerFd, [
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'room_id' => $roomId,
                'status' => 'playing',
                'cards' => json_encode($playerCards),
                'score' => $player['score'],
                'last_activity' => time()
            ]);
        }

        // Set initial game state
        $gameData['status'] = 'playing';
        $gameData['current_turn'] = 0; // Start with first player
        $gameData['game_started'] = true;
        $gameData['play_area'] = [];
        $gameData['last_card'] = null;
        $gameData['deck_count'] = count($gameData['deck']);
        $gameData['last_updated'] = time();
        $gameData['start_time'] = time();

        // Update room status
        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'playing',
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'],
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Notify all players that the game has started
        $this->notifyGameStarted($server, $roomId, $gameData);
    }

    public function startNewGameInRoom(Server $server, string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);

        // Reset the game room to waiting state
        $gameData = json_decode($room['game_data'], true);
        $gameData['deck'] = GameUtilities::createNewDeck();
        $gameData['current_turn'] = -1;
        $gameData['game_started'] = false;
        $gameData['play_area'] = [];
        $gameData['last_card'] = null;
        $gameData['last_updated'] = time();

        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'waiting',
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'],
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Reset player cards
        foreach ($gameData['players'] as $playerFd) {
            if ($this->storage->playerExists($playerFd)) {
                $player = $this->storage->getPlayer($playerFd);
                $this->storage->setPlayer($playerFd, [
                    'user_id' => $player['user_id'],
                    'username' => $player['username'],
                    'room_id' => $roomId,
                    'status' => 'waiting',
                    'cards' => '[]',
                    'score' => $player['score'],
                    'last_activity' => time()
                ]);
            }
        }

        // Notify players that a new game is ready
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'new_game_ready',
            'message' => 'A new game is ready to start!',
            'room_id' => $roomId
        ]);

        // Start the game if we have enough players
        if (count($gameData['players']) >= 2) {
            $this->startGame($server, $roomId);
        }
    }

    private function handlePlayCard(Server $server, int $fd, string $roomId, array $gameData, array $data): void
    {
        if (!isset($data['card_index'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Card index is required'
            ]));
            return;
        }

        $cardIndex = (int) $data['card_index'];
        $player = $this->storage->getPlayer($fd);
        $playerCards = json_decode($player['cards'], true);

        if (!isset($playerCards[$cardIndex])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Invalid card index'
            ]));
            return;
        }

        $card = $playerCards[$cardIndex];

        // Check if the card can be played (matching suit or value)
        if ($gameData['last_card'] !== null) {
            $lastCard = $gameData['last_card'];
            if ($lastCard['suit'] !== $card['suit'] && $lastCard['value'] !== $card['value']) {
                $server->push($fd, json_encode([
                    'type' => 'error',
                    'message' => 'Invalid move: card must match the suit or value of the last card'
                ]));
                return;
            }
        }

        // Remove the played card from player's hand
        array_splice($playerCards, $cardIndex, 1);

        // Add card to the play area in game state
        $gameData['play_area'][] = $card;
        $gameData['last_card'] = $card;

        // Update player's cards
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => $player['status'],
            'cards' => json_encode($playerCards),
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        // Update game data
        $this->updateGameData($roomId, $gameData);

        // Notify all players about the move
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'card_played',
            'player_id' => $player['user_id'],
            'username' => $player['username'],
            'card' => $card,
            'remaining_cards' => count($playerCards)
        ]);

        // Check if player has won (no cards left)
        if (empty($playerCards)) {
            $this->handlePlayerWin($server, $fd, $roomId);
        } else {
            // Move to next player
            $this->nextTurn($server, $roomId, $gameData);
        }
    }

    private function handleDrawCard(Server $server, int $fd, string $roomId, array $gameData): void
    {
        // Check if deck has cards
        if (empty($gameData['deck'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'No cards left in the deck'
            ]));
            return;
        }

        // Draw a card from the deck
        $card = array_pop($gameData['deck']);
        $gameData['deck_count'] = count($gameData['deck']);

        // Add card to player's hand
        $player = $this->storage->getPlayer($fd);
        $playerCards = json_decode($player['cards'], true);
        $playerCards[] = $card;

        // Update player's cards
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => $player['status'],
            'cards' => json_encode($playerCards),
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        // Update game data
        $this->updateGameData($roomId, $gameData);

        // Notify player about their new card
        $server->push($fd, json_encode([
            'type' => 'card_drawn',
            'card' => $card,
            'hand' => $playerCards
        ]));

        // Notify other players that this player drew a card
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoomExcept($server, $roomId, $fd, [
            'type' => 'player_drew_card',
            'player_id' => $player['user_id'],
            'username' => $player['username'],
            'cards_count' => count($playerCards),
            'deck_remaining' => count($gameData['deck'])
        ]);

        // Move to next player after drawing
        $this->nextTurn($server, $roomId, $gameData);
    }

    private function handlePassTurn(Server $server, int $fd, string $roomId, array $gameData): void
    {
        // Simply move to the next player
        $this->nextTurn($server, $roomId, $gameData);

        // Notify all players about the pass
        $player = $this->storage->getPlayer($fd);
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'player_passed',
            'player_id' => $player['user_id'],
            'username' => $player['username']
        ]);
    }

    private function nextTurn(Server $server, string $roomId, array $gameData): void
    {
        if (empty($gameData['players'])) {
            return;
        }

        // Move to next player
        $gameData['current_turn'] = ($gameData['current_turn'] + 1) % count($gameData['players']);
        $gameData['last_updated'] = time();

        // Update game data
        $this->updateGameData($roomId, $gameData);

        // Notify all players about the turn change
        $currentPlayerFd = $gameData['players'][$gameData['current_turn']] ?? null;
        if ($currentPlayerFd && $this->storage->playerExists($currentPlayerFd)) {
            $player = $this->storage->getPlayer($currentPlayerFd);
            $roomHandler = new RoomHandler($this->storage);
            $roomHandler->broadcastToRoom($server, $roomId, [
                'type' => 'turn_changed',
                'current_player_id' => $player['user_id'],
                'current_player_username' => $player['username'],
                'turn_index' => $gameData['current_turn']
            ]);
        }
    }

    private function updateGameData(string $roomId, array $gameData): void
    {
        $room = $this->storage->getRoom($roomId);
        if (!$room) {
            return;
        }

        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => $room['status'],
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'],
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);
    }

    private function handlePlayerWin(Server $server, int $fd, string $roomId): void
    {
        if (!$this->storage->playerExists($fd) || !$this->storage->roomExists($roomId)) {
            return;
        }

        $player = $this->storage->getPlayer($fd);
        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Update game status
        $gameData['status'] = 'ended';
        $gameData['winner'] = [
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ];
        $gameData['end_time'] = time();

        // Update room status
        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'ended',
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'],
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Increment player score
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => 'playing',
            'cards' => $player['cards'],
            'score' => $player['score'] + 1,
            'last_activity' => time()
        ]);

        // Notify all players about the win
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'game_over',
            'winner' => [
                'user_id' => $player['user_id'],
                'username' => $player['username']
            ],
            'game_duration' => time() - $gameData['start_time']
        ]);

        // Schedule a new game to start after a delay
        $server->task([
            'type' => 'start_new_game',
            'room_id' => $roomId,
            'delay' => 10 // 10 seconds
        ]);
    }

    public function handlePlayerLeavingGame(Server $server, string $roomId, int $fd, array $gameData): void
    {
        // If this was the current player's turn, move to next player
        $currentTurnIndex = $gameData['current_turn'];
        $currentTurnFd = $gameData['players'][$currentTurnIndex] ?? null;

        if ($currentTurnFd == $fd) {
            $this->nextTurn($server, $roomId, $gameData);
        }

        // If only one player remains, end the game
        if (count($gameData['players']) <= 1) {
            $this->endGameDueToPlayers($server, $roomId);
        }
    }

    private function endGameDueToPlayers(Server $server, string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Update game status
        $gameData['status'] = 'ended';
        $gameData['end_time'] = time();

        // Update room status
        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'waiting', // Set back to waiting for new players
            'max_players' => $room['max_players'],
            'current_players' => count($gameData['players']),
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        // Notify remaining players
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'game_ended',
            'reason' => 'Not enough players',
            'message' => 'The game has ended because there are not enough players.'
        ]);
    }

    public function processGameUpdate(Server $server, string $roomId): void
    {
        // This method can handle any periodic game updates
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);
        $gameData = json_decode($room['game_data'], true);

        // Example: Check if current player has been inactive for too long
        $currentTime = time();
        $turnTimeout = 30; // 30 seconds per turn

        if ($room['status'] === 'playing' &&
            isset($gameData['turn_start_time']) &&
            ($currentTime - $gameData['turn_start_time']) > $turnTimeout) {
            // Auto-pass turn for inactive player
            $this->nextTurn($server, $roomId, $gameData);
        }
    }

    private function notifyGameStarted(Server $server, string $roomId, array $gameData): void
    {
        $players = [];

        // Collect player information
        foreach ($gameData['players'] as $index => $playerFd) {
            if ($this->storage->playerExists($playerFd)) {
                $playerData = $this->storage->getPlayer($playerFd);
                $playerInfo = [
                    'user_id' => $playerData['user_id'],
                    'username' => $playerData['username'],
                    'cards_count' => count(json_decode($playerData['cards'], true)),
                    'is_current' => ($index === $gameData['current_turn'])
                ];
                $players[] = $playerInfo;

                // Send each player their cards
                $server->push($playerFd, json_encode([
                    'type' => 'your_cards',
                    'cards' => json_decode($playerData['cards'], true)
                ]));
            }
        }

        // Broadcast game started to all players
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'game_started',
            'players' => $players,
            'current_player_index' => $gameData['current_turn'],
            'current_player' => $players[$gameData['current_turn']] ?? null,
            'deck_remaining' => count($gameData['deck'])
        ]);
    }
}
