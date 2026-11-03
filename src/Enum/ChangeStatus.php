<?php

declare(strict_types=1);

namespace App\Enum;

enum ChangeStatus: string
{
    case SOLICITADO = 'Solicitado';
    case EN_REVISION = 'En Revisión';
    case APROBADO = 'Aprobado';
    case RECHAZADO = 'Rechazado';
    case IMPLEMENTADO = 'Implementado';
}
