<?php

declare(strict_types=1);

namespace App\Enum;

enum CiRelationshipType: string
{
    case HOSTED_ON = 'hosted_on';
    case DEPENDS_ON = 'depends_on';
    case CONNECTED_TO = 'connected_to';
}
