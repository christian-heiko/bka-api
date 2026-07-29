<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Data\Image;
use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Exception\InvalidImageException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/** Findings #2 and #3. */
final class ImageTest extends BaseTestCase {

    private function legend(): Text {
        return Text::make('de', 'Legend');
    }

    #[Test]
    public function an_unreadable_path_throws_instead_of_encoding_an_empty_image(): void {
        // Regression for #2: file_get_contents() returned false, base64_encode(false)
        // gave "", and an event was posted carrying a blank image.
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessageMatches('/Could not read image/');

        Image::makeFromPath('/does/not/exist.jpg', $this->legend());
    }

    #[Test]
    public function it_reads_and_encodes_a_real_file(): void {
        $path = tempnam(sys_get_temp_dir(), 'bka') . '.png';
        file_put_contents($path, 'binary-content');

        try {
            $payload = Image::makeFromPath($path, $this->legend())->toArray();

            self::assertSame('.png', $payload['fileExtension']);
            self::assertSame('binary-content', base64_decode($payload['base64File']));
            self::assertSame(['de' => 'Legend'], $payload['legend']);
        } finally {
            unlink($path);
        }
    }

    /** Regression for #3: both of these used to throw "Unsupported file extension". */
    #[Test]
    #[DataProvider('acceptedExtensions')]
    public function it_normalises_realistic_paths_and_urls(string $given, string $expected): void {
        $image = new Image($given, 'ZGF0YQ==', $this->legend());

        self::assertSame($expected, $image->toArray()['fileExtension']);
    }

    public static function acceptedExtensions(): array {
        return [
            'uppercase' => ['.JPG', '.jpg'],
            'mixed case' => ['.JpEg', '.jpeg'],
            'already lower' => ['.png', '.png'],
        ];
    }

    #[Test]
    #[DataProvider('realisticPaths')]
    public function extensions_are_derived_from_the_path_not_the_query_string(string $path, string $expected): void {
        $reflected = new \ReflectionMethod(Image::class, 'extensionFrom');

        self::assertSame($expected, $reflected->invoke(null, $path));
    }

    public static function realisticPaths(): array {
        return [
            'cdn url with query' => ['https://cdn.example/img.png?w=100&h=50', '.png'],
            'uppercase local file' => ['/tmp/photo.JPG', '.jpg'],
            'plain local file' => ['/tmp/photo.jpeg', '.jpeg'],
            'url without query' => ['https://cdn.example/a/b/photo.PNG', '.png'],
        ];
    }

    #[Test]
    public function a_genuinely_unsupported_extension_still_throws(): void {
        $this->expectException(InvalidImageException::class);
        $this->expectExceptionMessageMatches('/Unsupported file extension/');

        (new Image('.gif', 'ZGF0YQ==', $this->legend()))->toArray();
    }

}
