<?php

declare(strict_types=1);

namespace App\Enum;

enum TicketType: string
{
    case INCIDENTE = 'Incidente';
    case PETICION = 'Peticion';

    public function label(): string
    {
        return match ($this) {
            self::INCIDENTE => 'Incidente',
            self::PETICION => 'Petición de Servicio',
        };
    }
}
