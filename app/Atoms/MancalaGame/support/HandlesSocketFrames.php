<?php

declare(strict_types=1);

namespace App\Atoms\MancalaGame\Support;

use Atoms\Websocket\Connection;
use Atoms\Websocket\Message;

/** Reading move frames from a socket, and answering it with errors. */
trait HandlesSocketFrames
{
    /** @return array{pit: int, revision: int}|null */
    private function parseMove(Message $msg): ?array
    {
        try {
            $frame = $msg->json();
        } catch (\JsonException) {
            return null;
        }

        if (($frame['kind'] ?? null) !== 'move' || !is_int($frame['pit'] ?? null) || !is_int($frame['revision'] ?? null)) {
            return null;
        }

        return ['pit' => $frame['pit'], 'revision' => $frame['revision']];
    }

    private function refuse(Connection $conn, string $code, int $closeCode, string $reason): void
    {
        $this->fail($conn, $code);
        $conn->close($closeCode, $reason);
    }

    /** @param array<string, mixed>|null $state */
    private function fail(Connection $conn, string $code, ?array $state = null): void
    {
        $frame = ['kind' => 'error', 'code' => $code];
        if ($state !== null) {
            $frame['state'] = $state;
        }

        $conn->sendJson($frame);
    }
}
