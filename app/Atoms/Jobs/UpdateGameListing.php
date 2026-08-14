<?php

declare(strict_types=1);

namespace App\Atoms\Jobs;

use App\Atoms\GameDirectory;
use Atoms\AtomJob;
use Atoms\Laravel\Facades\Atoms;

/** Keeps the public lobby eventually consistent without coupling game turns to it. */
final class UpdateGameListing extends AtomJob
{
    public function __construct(
        public readonly string $gameId,
        public readonly string $status,
        public readonly string $expiresAt,
    ) {
    }

    public function handle(): void
    {
        $expiresAt = $this->expiresAt === '' ? null : new \DateTimeImmutable($this->expiresAt);

        Atoms::call(
            'GameDirectory',
            GameDirectory::ID,
            'updateStatus',
            [$this->gameId, $this->status, new \DateTimeImmutable(), $expiresAt],
            GameDirectory::class,
        );
    }
}
