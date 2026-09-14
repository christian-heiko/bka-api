<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Enum;

/**
 * Ticketing providers the v2 API accepts for a ticket link, `other` for anything else.
 *
 * Collected from the API's validation (it rejects unknown values); the list may not be
 * exhaustive, which is what `other` is for.
 */
enum TicketingDesignation: string {
    case petzi = 'petzi';
    case eventfrog = 'eventfrog';
    case ticketcorner = 'ticketcorner';
    case starticket = 'starticket';
    case ticketmaster = 'ticketmaster';
    case other = 'other';

    /** Guesses the provider from a ticket URL's host, e.g. www.petzi.ch → petzi. */
    public static function fromUrl(string $url): self {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        foreach (self::cases() as $provider) {
            if ($provider !== self::other && str_contains($host, $provider->value)) {
                return $provider;
            }
        }

        return self::other;
    }
}
