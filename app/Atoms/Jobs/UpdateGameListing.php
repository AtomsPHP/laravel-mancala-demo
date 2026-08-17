<?php

declare(strict_types=1);

namespace App\Atoms\Jobs;

use App\Atoms\GameDirectory;
use Atoms\AtomJob;
use Atoms\Laravel\AtomsManager;

/** Keeps the public lobby eventually consistent without coupling game turns to it. */
final class UpdateGameListing extends AtomJob
{
    public function __construct(
        public readonly string $gameId,
        public readonly string $status,
        public readonly string $expiresAt,
    ) {
    }

    /** The manager rather than the facade: only the injected form carries the generic. */
    public function handle(AtomsManager $atoms): void
    {
        $expiresAt = $this->expiresAt === '' ? null : new \DateTimeImmutable($this->expiresAt);

        $atoms->get(GameDirectory::class, GameDirectory::ID)
            ->updateStatus($this->gameId, $this->status, new \DateTimeImmutable(), $expiresAt);
    }
}
