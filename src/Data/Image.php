<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Exception\InvalidImageException;
use ChristianHeiko\Bka\Interface\ToArray;

/** @phpstan-consistent-constructor Subclasses must keep the constructor signature; makeFromPath() relies on it. */
class Image implements ToArray {

    /** @var list<string> */
    protected static array $allowedFileExtensions = ['.jpg', '.jpeg', '.png'];

    public function __construct(
        protected string $fileExtension,
        protected string $base64File,
        protected Text $legend
    ) { }

    /**
     * Reads a local path or remote URL and base64-encodes it.
     *
     * @throws InvalidImageException if the file cannot be read.
     */
    public static function makeFromPath(string $filePath, Text $legend): static {
        $file = @file_get_contents($filePath);

        // Without this guard base64_encode(false) yields "", and the API happily
        // accepts an event carrying a blank image.
        if ($file === false) {
            throw InvalidImageException::unreadable($filePath);
        }

        return new static(
            self::extensionFrom($filePath),
            base64_encode($file),
            $legend
        );
    }

    /**
     * Query strings are stripped first, so CDN URLs such as `…/img.png?w=100`
     * yield `.png` rather than `.png?w=100`.
     */
    protected static function extensionFrom(string $filePath): string {
        $path = parse_url($filePath, PHP_URL_PATH) ?: $filePath;

        return '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        $this->checkExtension();

        return [
            // Lower-cased so a directly-constructed `.JPG` still matches the API's enum.
            'fileExtension' => strtolower($this->fileExtension),
            'base64File' => $this->base64File,
            'legend' => $this->legend->toArray(),
        ];
    }

    protected function checkExtension(): void {
        if (!in_array(strtolower($this->fileExtension), self::$allowedFileExtensions, true)) {
            throw InvalidImageException::unsupportedExtension(
                $this->fileExtension,
                self::$allowedFileExtensions
            );
        }
    }

}
