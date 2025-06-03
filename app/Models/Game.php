<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The Game model represents a card game in the system.
 * It's the base model that GameRoom extends for specialized room functionality.
 */
class Game extends Model
{
    use HasFactory;

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
        'started_at',
        'ended_at',
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
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The players in this game.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function players()
    {
        return $this->hasMany(GamePlayer::class)->with('user');
    }

    /**
     * The users who are playing this game.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'game_players')
                    ->withPivot('status', 'score', 'cards')
                    ->withTimestamps();
    }

    /**
     * Scope a query to only include active games.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'playing');
    }

    /**
     * Scope a query to only include waiting games.
     */
    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }
}
