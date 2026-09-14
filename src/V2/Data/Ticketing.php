<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2\Data;

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\TicketingDesignation;
use ChristianHeiko\Bka\Interface\ToArray;

/**
 * A ticket link.
 *
 * This is where v2 keeps them. The event's `ticketing_url` in the read model is legacy
 * that only v1 could write: v2 accepts the key and silently drops it.
 *
 * @phpstan-consistent-constructor Subclasses must keep the constructor signature; fromUrl() relies on it.
 */
class Ticketing implements ToArray {

    /**
     * @param Text $url The link, per language.
     * @param Text $designation Its caption, e.g. "Vorverkauf" — the API requires one.
     */
    public function __construct(
        public Text $url,
        public Text $designation,
        public TicketingDesignation $provider = TicketingDesignation::other,
    ) { }

    /** A link in one language, with the provider guessed from its host. */
    public static function fromUrl(string $url, Text $designation, string $language = 'de'): static {
        return new static(Text::make($language, $url), $designation, TicketingDesignation::fromUrl($url));
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'ticketing_designation' => $this->provider->value,
            'labels' => [
                'ticketingUrl' => $this->url->toArray(),
                'designation' => $this->designation->toArray(),
            ],
        ];
    }

}
