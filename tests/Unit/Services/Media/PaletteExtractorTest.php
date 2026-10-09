<?php

namespace Tests\Unit\Services\Media;

use App\Services\Media\PaletteExtractor;
use PHPUnit\Framework\TestCase;

class PaletteExtractorTest extends TestCase
{
    public function test_product_photos_on_pure_white_and_black_do_not_fail(): void
    {
        $image = imagecreatetruecolor(40, 40);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 14, 4, 26, 36, imagecolorallocate($image, 120, 20, 40));
        imagefilledrectangle($image, 0, 38, 39, 39, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        $palette = (new PaletteExtractor)->extract($png);

        $this->assertSame('#ffffff', $palette['dominant']);
        $this->assertSame('#781428', $palette['vibrant']);
    }
}
