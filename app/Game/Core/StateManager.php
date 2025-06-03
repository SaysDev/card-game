<?php

namespace App\Game\Core;

use OpenSwoole\Table;

class StateManager
{
    public Table $players;
    public Table $rooms;

    public function __construct()
    {
    }
}