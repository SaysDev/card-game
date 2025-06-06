<?php

namespace App\Servers\Utilities;

class GameUtilities
{
    /**
     * Create a new deck of cards
     *
     * @return array The shuffled deck of cards
     */
    public static function createNewDeck(): array
    {
        $suits = [
            ['key' => 'H', 'name' => 'Kier', 'symbol' => '❤️', 'colorClass' => 'text-red-600'],
            ['key' => 'D', 'name' => 'Karo', 'symbol' => '♦️', 'colorClass' => 'text-red-600'],
            ['key' => 'C', 'name' => 'Trefl', 'symbol' => '♣️', 'colorClass' => 'text-gray-900'],
            ['key' => 'S', 'name' => 'Pik', 'symbol' => '♠️', 'colorClass' => 'text-gray-900']
        ];

        // Based on the frontend, starting from 9
        $ranks = [
            ['key' => '9', 'name' => 'Dziewiątka', 'rankValue' => 9],
            ['key' => '10', 'name' => 'Dziesiątka', 'rankValue' => 10],
            ['key' => 'J', 'name' => 'Walet', 'rankValue' => 11],
            ['key' => 'Q', 'name' => 'Dama', 'rankValue' => 12],
            ['key' => 'K', 'name' => 'Król', 'rankValue' => 13],
            ['key' => 'A', 'name' => 'As', 'rankValue' => 14]
        ];

        $deck = [];
        foreach ($suits as $suit) {
            foreach ($ranks as $rank) {
                $deck[] = [
                    'value' => "{$suit['key']}_{$rank['key']}",
                    'header' => "{$rank['name']} {$suit['name']}",
                    'cardRank' => $rank['key'],
                    'cardSymbol' => $suit['symbol'],
                    'symbolColorClass' => $suit['colorClass'],
                    'rankValue' => $rank['rankValue'],
                    'suit' => $suit['key'],
                    'rank' => $rank['key']
                ];
            }
        }

        shuffle($deck);

        return $deck;
    }

    /**
     * Check if a card can be played on top of another card
     *
     * @param array $playedCard The card being played
     * @param array $topCard The current top card
     * @return bool Whether the move is valid
     */
    public static function isValidMove(array $playedCard, array $topCard): bool
    {
        // A card can be played if it matches the suit or value of the top card
        return $playedCard['suit'] === $topCard['suit'] || $playedCard['value'] === $topCard['value'];
    }

    /**
     * Calculate the card's value for scoring purposes
     *
     * @param array $card The card
     * @return int The card's point value
     */
    public static function getCardValue(array $card): int
    {
        switch ($card['value']) {
            case 'A':
                return 11;
            case 'K':
            case 'Q':
            case 'J':
                return 10;
            default:
                return (int) $card['value'];
        }
    }

    /**
     * Generate a unique room ID
     *
     * @return string The room ID
     */
    public static function generateRoomId(): string
    {
        return 'room_' . uniqid();
    }

    /**
     * Convert a timestamp to a human-readable duration
     *
     * @param int $seconds Number of seconds
     * @return string Formatted duration string
     */
    public static function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '') .
                   ($remainingSeconds > 0 ? ' and ' . $remainingSeconds . ' second' . ($remainingSeconds !== 1 ? 's' : '') : '');
        }

        $hours = floor($seconds / 3600);
        $remainingMinutes = floor(($seconds % 3600) / 60);
        return $hours . ' hour' . ($hours !== 1 ? 's' : '') .
               ($remainingMinutes > 0 ? ' and ' . $remainingMinutes . ' minute' . ($remainingMinutes !== 1 ? 's' : '') : '');
    }
}
