<?php

namespace ChristianHeiko\Bka\Enum;

enum EventStatus: string {
    case confirmed = 'confirmed';
    case canceled = 'canceled';
    case postponed = 'postponed';
    case full = 'full';
}
