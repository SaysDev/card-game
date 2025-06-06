<?php

namespace App\Servers\Handlers;

use App\Servers\Storage\MemoryStorage;
use App\Servers\Utilities\GameUtilities;
use OpenSwoole\WebSocket\Server;
use App\Servers\WebSocketServer;
use App\Servers\Utilities\Logger;

class GameHandler extends BaseMessageHandler
{
    private ?WebSocketServer $wsServer = null;

    public function __construct(Server $server, MemoryStorage $storage, Logger $logger)
    {
        parent::__construct($server, $storage, $logger);
    }

    public function handlePlayerReady(int $fd, array $data): void
    {
        if (!isset($data['room_id'])) {
            $this->sendError($fd, "Missing room_id");
            return;
        }

        $roomId = $data['room_id'];
        $player = $this->validatePlayer($fd);
        if (!$player) {
            return;
        }

        $room = $this->validateRoom($roomId);
        if (!$room) {
            $this->sendError($fd, "Room not found");
            return;
        }

        $this->logger->info("Player ready", [
            'room_id' => $roomId,
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ]);

        $gameData = json_decode($room['game_data'], true);
        foreach ($gameData['players'] as &$p) {
            if ($p['fd'] === $fd) {
                $p['ready'] = true;
                break;
            }
        }

        $room['game_data'] = json_encode($gameData);
        $this->storage->setRoom($roomId, $room);

        // Check if all players are ready
        $this->checkAllPlayersReady($roomId);
    }

    private function checkAllPlayersReady(string $roomId): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $gameData = json_decode($room['game_data'], true);
        $allReady = true;
        $playerCount = count($gameData['players']);

        foreach ($gameData['players'] as $player) {
            if (!$player['ready']) {
                $allReady = false;
                break;
            }
        }

        if ($allReady && $playerCount >= 2) {
            $this->startGame($roomId);
        }
    }

    private function startGame(string $roomId): void
    {
        $room = $this->validateRoom($roomId);
        if (!$room) {
            return;
        }

        $this->logger->info("Starting game", ['room_id' => $roomId]);

        $room['status'] = 'playing';
        $gameData = json_decode($room['game_data'], true);
        $gameData['game_started'] = true;
        $gameData['current_turn'] = 0;
        $room['game_data'] = json_encode($gameData);

        $this->storage->setRoom($roomId, $room);

        $this->broadcastToRoom($roomId, [
            'type' => 'game_started',
            'room_id' => $roomId,
            'room' => $room
        ]);
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

        // Wyślij akcję do GameServera
        if ($this->wsServer && method_exists($this->wsServer, 'sendToGameServer')) {
            $this->wsServer->sendToGameServer([
                'action' => 'player_action',
                'room_id' => $roomId,
                'move' => $data
            ]);
        }

        $gameData = json_decode($room['game_data'], true);

        $currentPlayerFd = $gameData['players'][$gameData['current_turn']] ?? null;

        if ($currentPlayerFd != $fd) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'It\'s not your turn'
            ]));
            return;
        }

        if (!isset($data['action_type'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Invalid action'
            ]));
            return;
        }

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

    public function startNewGameInRoom(Server $server, string $roomId): void
    {
        if (!$this->storage->roomExists($roomId)) {
            return;
        }

        $room = $this->storage->getRoom($roomId);

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

        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'new_game_ready',
            'message' => 'A new game is ready to start!',
            'room_id' => $roomId
        ]);

        if (count($gameData['players']) >= 2) {
            $this->startGame($roomId);
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

        array_splice($playerCards, $cardIndex, 1);

        $gameData['play_area'][] = $card;
        $gameData['last_card'] = $card;
        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => $player['status'],
            'cards' => json_encode($playerCards),
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        $this->updateGameData($roomId, $gameData);
        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoom($server, $roomId, [
            'type' => 'card_played',
            'player_id' => $player['user_id'],
            'username' => $player['username'],
            'card' => $card,
            'remaining_cards' => count($playerCards)
        ]);

        if (empty($playerCards)) {
            $this->handlePlayerWin($server, $fd, $roomId);
        } else {
            $this->nextTurn($server, $roomId, $gameData);
        }
    }

    private function handleDrawCard(Server $server, int $fd, string $roomId, array $gameData): void
    {
        if (empty($gameData['deck'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'No cards left in the deck'
            ]));
            return;
        }
        $card = array_pop($gameData['deck']);
        $gameData['deck_count'] = count($gameData['deck']);

        // Add card to player's hand
        $player = $this->storage->getPlayer($fd);
        $playerCards = json_decode($player['cards'], true);
        $playerCards[] = $card;

        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => $player['status'],
            'cards' => json_encode($playerCards),
            'score' => $player['score'],
            'last_activity' => time()
        ]);

        $this->updateGameData($roomId, $gameData);
        $server->push($fd, json_encode([
            'type' => 'card_drawn',
            'card' => $card,
            'hand' => $playerCards
        ]));

        $roomHandler = new RoomHandler($this->storage);
        $roomHandler->broadcastToRoomExcept($server, $roomId, $fd, [
            'type' => 'player_drew_card',
            'player_id' => $player['user_id'],
            'username' => $player['username'],
            'cards_count' => count($playerCards),
            'deck_remaining' => count($gameData['deck'])
        ]);

        $this->nextTurn($server, $roomId, $gameData);
    }

    private function handlePassTurn(Server $server, int $fd, string $roomId, array $gameData): void
    {
        $this->nextTurn($server, $roomId, $gameData);
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

        $gameData['current_turn'] = ($gameData['current_turn'] + 1) % count($gameData['players']);
        $gameData['last_updated'] = time();
        $this->updateGameData($roomId, $gameData);
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

        $gameData['status'] = 'ended';
        $gameData['winner'] = [
            'user_id' => $player['user_id'],
            'username' => $player['username']
        ];
        $gameData['end_time'] = time();

        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'ended',
            'max_players' => $room['max_players'],
            'current_players' => $room['current_players'],
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

        $this->storage->setPlayer($fd, [
            'user_id' => $player['user_id'],
            'username' => $player['username'],
            'room_id' => $player['room_id'],
            'status' => 'playing',
            'cards' => $player['cards'],
            'score' => $player['score'] + 1,
            'last_activity' => time()
        ]);

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
        $currentTurnIndex = $gameData['current_turn'];
        $currentTurnFd = $gameData['players'][$currentTurnIndex] ?? null;

        if ($currentTurnFd == $fd) {
            $this->nextTurn($server, $roomId, $gameData);
        }

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

        $gameData['status'] = 'ended';
        $gameData['end_time'] = time();

        $this->storage->updateRoom($roomId, [
            'name' => $room['name'],
            'status' => 'waiting', // Set back to waiting for new players
            'max_players' => $room['max_players'],
            'current_players' => count($gameData['players']),
            'game_data' => json_encode($gameData),
            'created_at' => $room['created_at']
        ]);

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
            $this->nextTurn($server, $roomId, $gameData);
        }
    }

    private function notifyGameStarted(Server $server, string $roomId, array $gameData): void
    {
        $players = [];

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

                $server->push($playerFd, json_encode([
                    'type' => 'your_cards',
                    'cards' => json_decode($playerData['cards'], true)
                ]));
            }
        }

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
