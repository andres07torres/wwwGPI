<?php

declare(strict_types=1);

namespace App\Enum;

enum ProblemStatus: string
{
    case BAJO_INVESTIGACION = 'Bajo Investigación';
    case IDENTIFICADO = 'Identificado';
    case RESUELTO = 'Resuelto';
}
