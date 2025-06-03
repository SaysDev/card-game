use OpenSwoole\Table;

class Matchmaker
{
    private Table $gameServersTable;

    public function __construct(Table $gameServersTable)
    {
        $this->gameServersTable = $gameServersTable;
    }

    public function findBestServerId(): ?string
    {
        if ($this->gameServersTable->count() === 0) {
            return null;
        }

        // For now, just return the first available server
        // In the future, we can implement more sophisticated server selection
        foreach ($this->gameServersTable as $serverId => $row) {
            return $serverId;
        }

        return null;
    }
} 