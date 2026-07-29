<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Enum;

enum PublicationStatus: string {
    case publish = 'publish';
    case draft = 'draft';
    case to_validate = 'to_validate';
}
