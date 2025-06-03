<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * GameRoom is a specialized version of the Game model specifically for room management.
 * It extends the Game model but adds room-specific functionality.
 *
 * This model is used by the WebSocket server and card game system to manage game rooms.
 */
class GameRoom extends Game
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'games';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'status',
        'max_players',
        'current_players',
        'game_data',
        'created_at',
        'updated_at'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'max_players' => 'integer',
        'current_players' => 'integer',
        'game_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the room_id (alias for id)
     *
     * This accessor is used to maintain compatibility with the WebSocket server,
     * which uses room_id as the identifier for rooms. The model uses the standard 'id'
     * column in the database, but we present it as room_id in the API.
     *
     * @return string
     */
    public function getRoomIdAttribute(): string
    {
        return (string) $this->id;
    }

    /**
     * Set the room_id (will set the id attribute)
     *
     * This mutator allows setting the id through the room_id attribute,
     * maintaining compatibility with the WebSocket server.
     *
     * @param mixed $value
     * @return void
     */
    public function setRoomIdAttribute($value): void
    {
        $this->attributes['id'] = is_numeric($value) ? (int)$value : null;
    }
}
