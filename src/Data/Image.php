<?php

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class Image implements ToArray {

    protected static array $allowedFileExtensions = ['.jpg', '.jpeg', '.png'];

    public function __construct(
        protected string $fileExtension,
        protected string $base64File,
        protected Text $legend
    ) { }

    public static function makeFromPath(string $filePath, Text $legend): static {
        $file = file_get_contents($filePath);
        $base64 = base64_encode($file);

        return new static(
            '.' . pathinfo($filePath, PATHINFO_EXTENSION),
            $base64,
            $legend
        );
    }

    public function toArray(): array {
        $this->checkExtension();

        return [
            'fileExtension' => $this->fileExtension,
            'base64File' => $this->base64File,
            'legend' => $this->legend->toArray(),
        ];
    }

    protected function checkExtension(): void {
        if (!in_array($this->fileExtension, self::$allowedFileExtensions)) {
            throw new \Exception('Unsupported file extension: '
                . $this->fileExtension. '
                . Should be one of '
                . implode(', ', self::$allowedFileExtensions)
            );
        }
    }

}
