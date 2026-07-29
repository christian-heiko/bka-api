<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Enum;

enum SpecialRate: string {
    case free_entry = 'free_entry';
    case free_price = 'free_price';
}
