<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The games this user is participating in
     */
    public function games(): BelongsToMany
    {
        return $this->belongsToMany(Game::class, 'game_players')
                    ->withPivot('status', 'score', 'cards')
                    ->withTimestamps();
    }

    /**
     * Get the active game the user is currently playing
     */


    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the active game the user is currently playing
     */
    public function activeGame()
    {
        return $this->games()
                    ->where(function($query) {
                        $query->where('games.status', 'waiting')
                              ->orWhere('games.status', 'playing');
                    })
                    ->first();
    }

    /**
     * Check if user is currently in a game
     */
    public function isInGame()
    {
        return $this->activeGame() !== null;
    }
}
