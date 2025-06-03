<?php

namespace App\Game\Core;

use Swoole\Table;

class Matchmaker
{
    private Table $gameServersTable;

    public function __construct(Table $gameServersTable)
    {
        $this->gameServersTable = $gameServersTable;
    }

    public function findBestServer(): ?array
    {
        $best = null;
        $minLoad = 100;
        foreach ($this->gameServersTable as $row) {
            // Na czas debugowania nie filtrujemy po load ani last_ping
            if ($row['load'] < $minLoad) {
                $minLoad = $row['load'];
                $best = $row;
            }
        }
        return $best;
    }

    public function getServerLoad(int $serverId): int
    {
        $server = $this->gameServersTable->get($serverId);
        return $server ? $server['load'] : 0;
    }

    public function getAvailableServers(): array
    {
        $servers = [];
        foreach ($this->gameServersTable as $serverId => $server) {
            $servers[$serverId] = [
                'id' => $serverId,
                'ip' => $server['ip'],
                'port' => $server['port'],
                'load' => $server['load'],
                'fd' => $server['fd']
            ];
        }
        return $servers;
    }
}