<?php

namespace App\Game\Models;

class Room
{
    public $id;
    public array $players = [];
    public string $status = 'waiting';
    public string $visibility = 'public';
    public string $code = '';
    public string $name = '';
    
    public int $currentPlayers = 0;
    public int $maxPlayers = 2;

    public DateTime $createdAt;
    public DateTime $expiresAt;
    
    public function __construct(string $id, string $name, int $maxPlayers, DateTime $expiresAt, string $visibility = 'public', string $code = '')
    {
        $this->id = $id;
        $this->name = $name;
        $this->maxPlayers = $maxPlayers;
        $this->expiresAt = $expiresAt;
        $this->visibility = $visibility;
        $this->code = $code;
        $this->createdAt = now();
    }

    public function addPlayer(int $client_id, string $name)
    {
        $this->players[$client_id] = [
            'id' => $client_id,
            'name' => $name, 
            'status' => 'not_ready',
        ];

        $this->currentPlayers++;
    }

    public function removePlayer(int $client_id)
    {
        unset($this->players[$client_id]);
        $this->currentPlayers--;
    }

    public function getPlayer(int $client_id)
    {
        return $this->players[$client_id];
    }

    public function setPlayerStatus(int $client_id, string $status)
    {
        $this->players[$client_id]['status'] = $status;
    }

    public function togglePlayerReady(int $client_id)
    {
        $this->players[$client_id]['status'] = $this->players[$client_id]['status'] === 'ready' ? 'not_ready' : 'ready';
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < now();
    }

    public function tick(): string
    {
        if($this->isExpired()) {
            $this->status = 'expired';
        
        }

    }
}