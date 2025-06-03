<?php

namespace App\Servers\Handlers\methods;

use App\Servers\Storage\MemoryStorage;
use OpenSwoole\WebSocket\Server;

class CardHandler {

    public function handlePlayCard(Server $server, int $fd, array $data): void {
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

        if (!isset($data['card_index'])) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Card index is required'
            ]));
            return;
        }

        $cardIndex = (int) $data['card_index'];
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
}
