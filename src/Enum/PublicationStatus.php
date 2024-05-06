<?php

namespace ChristianHeiko\Bka\Enum;

enum PublicationStatus: string {
    case publish = 'publish';
    case draft = 'draft';
    case to_validate = 'to_validate';
}
