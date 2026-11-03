<?php

declare(strict_types=1);

namespace App\Enum;

enum CiItemStatus: string
{
    case ACTIVO = 'Activo';
    case MANTENIMIENTO = 'Mantenimiento';
    case DADO_DE_BAJA = 'Dado de Baja';
}
