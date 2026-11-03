<?php

declare(strict_types=1);

namespace App\Enum;

enum ChangeRiskLevel: string
{
    case BAJO = 'Bajo';
    case MEDIO = 'Medio';
    case ALTO = 'Alto';
    case CRITICO = 'Crítico';
}
