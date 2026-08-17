<?php

declare(strict_types=1);

namespace App\Atoms\Shared;

/**
 * An immutable Mancala board: twelve pits and two stores.
 *
 * Pure rules, no storage and no runtime — the Atom owns persistence, this
 * owns what a move means. Pits 0-5 belong to seat 0, pits 6-11 to seat 1.
 * Sowing walks a fourteen-slot ring where slots 6 and 13 are the stores.
 */
final class Board
{
    public const PITS_PER_SIDE = 6;
    public const PIT_COUNT = 12;
    private const RING = 14;
    private const OPENING_STONES = 4;

    /**
     * @param array<int, int> $pits stones in each of the twelve pits
     * @param array<int, int> $stores captured stones, indexed by seat
     */
    public function __construct(
        public readonly array $pits,
        public readonly array $stores,
    ) {
    }

    public static function opening(): self
    {
        return new self(array_fill(0, self::PIT_COUNT, self::OPENING_STONES), [0, 0]);
    }

    public static function owns(int $seat, int $pit): bool
    {
        return intdiv($pit, self::PITS_PER_SIDE) === $seat && $pit >= 0 && $pit < self::PIT_COUNT;
    }

    public function stones(int $pit): int
    {
        return $this->pits[$pit] ?? 0;
    }

    /**
     * Sow the stones from $sourcePit counter-clockwise, then resolve the
     * capture and the end of the game. The caller has already checked that the
     * move is legal.
     */
    public function play(int $seat, int $sourcePit): Move
    {
        $pits = $this->pits;
        $stores = $this->stores;
        $stones = $pits[$sourcePit];
        $pits[$sourcePit] = 0;

        $position = $sourcePit < self::PITS_PER_SIDE ? $sourcePit : $sourcePit + 1;
        $path = [];
        $lastPit = null;
        $endedInOwnStore = false;

        while ($stones > 0) {
            $position = ($position + 1) % self::RING;

            if ($position === self::storeOf(1 - $seat)) {
                continue; // You never sow into your opponent's store.
            }

            if ($position === self::storeOf($seat)) {
                $stores[$seat]++;
                $lastPit = null;
                $endedInOwnStore = true;
                $path[] = ['kind' => 'store', 'player' => $seat];
            } else {
                $pit = $position < self::PITS_PER_SIDE ? $position : $position - 1;
                $pits[$pit]++;
                $lastPit = $pit;
                $endedInOwnStore = false;
                $path[] = ['kind' => 'pit', 'index' => $pit];
            }

            $stones--;
        }

        $capture = null;
        if ($lastPit !== null && self::owns($seat, $lastPit) && $pits[$lastPit] === 1) {
            $opposite = self::PIT_COUNT - 1 - $lastPit;
            if ($pits[$opposite] > 0) {
                $capture = ['pit' => $lastPit, 'opposite' => $opposite, 'stones' => $pits[$opposite] + 1];
                $stores[$seat] += $capture['stones'];
                $pits[$lastPit] = 0;
                $pits[$opposite] = 0;
            }
        }

        $board = new self($pits, $stores);
        $finished = $board->sideIsEmpty(0) || $board->sideIsEmpty(1);

        if ($finished) {
            $board = $board->swept();
        }

        return new Move($board, $seat, $sourcePit, $path, $capture, !$finished && $endedInOwnStore, $finished);
    }

    public function sideIsEmpty(int $seat): bool
    {
        return array_sum(array_slice($this->pits, $seat * self::PITS_PER_SIDE, self::PITS_PER_SIDE)) === 0;
    }

    /** The player ahead on stones once the board is empty; -1 marks a draw. */
    public function winner(): int
    {
        if ($this->stores[0] === $this->stores[1]) {
            return -1;
        }

        return $this->stores[0] > $this->stores[1] ? 0 : 1;
    }

    /** Each player banks whatever is left on their own side. */
    private function swept(): self
    {
        $stores = $this->stores;
        foreach ($this->pits as $pit => $stones) {
            $stores[intdiv($pit, self::PITS_PER_SIDE)] += $stones;
        }

        return new self(array_fill(0, self::PIT_COUNT, 0), $stores);
    }

    private static function storeOf(int $seat): int
    {
        return $seat === 0 ? self::PITS_PER_SIDE : self::RING - 1;
    }
}
