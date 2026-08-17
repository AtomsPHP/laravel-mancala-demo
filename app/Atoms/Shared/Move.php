<?php

declare(strict_types=1);

namespace App\Atoms\Shared;

/**
 * One resolved move: the board it produced, and the ordered drop path the
 * browsers replay so every connected client animates the same action.
 */
final class Move
{
    /**
     * @param list<array{kind: 'pit', index: int}|array{kind: 'store', player: int}> $path
     * @param array{pit: int, opposite: int, stones: int}|null $capture
     */
    public function __construct(
        public readonly Board $board,
        public readonly int $actor,
        public readonly int $sourcePit,
        public readonly array $path,
        public readonly ?array $capture,
        public readonly bool $extraTurn,
        public readonly bool $finished,
    ) {
    }

    public function status(): string
    {
        return $this->finished ? 'finished' : 'active';
    }

    /** Null once the game is over; landing in your own store buys another turn. */
    public function nextTurn(): ?int
    {
        if ($this->finished) {
            return null;
        }

        return $this->extraTurn ? $this->actor : 1 - $this->actor;
    }

    public function winner(): ?int
    {
        return $this->finished ? $this->board->winner() : null;
    }
}
