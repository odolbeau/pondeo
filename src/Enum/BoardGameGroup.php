<?php

declare(strict_types=1);

namespace App\Enum;

/** The two parent POI categories of the board game places; the value is the category code. */
enum BoardGameGroup: string
{
    case Association = 'board_game_association';
    case Store = 'board_game_store';

    /**
     * @return list<BoardGameType>
     */
    public function types(): array
    {
        return array_values(array_filter(BoardGameType::cases(), fn (BoardGameType $type): bool => $this === $type->group()));
    }
}
