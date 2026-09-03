<?php

declare(strict_types=1);

namespace App\Atoms\Shared;

enum GameStatus: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Finished = 'finished';
    case Expired = 'expired';
}
