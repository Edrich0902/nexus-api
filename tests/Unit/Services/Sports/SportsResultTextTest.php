<?php

namespace Tests\Unit\Services\Sports;

use App\Services\Sports\SportsResultText;
use Tests\TestCase;

class SportsResultTextTest extends TestCase
{
    public function test_strips_html_breaks_and_tags(): void
    {
        $cleaned = SportsResultText::clean(
            'Japan Rugby First Half:<br> 12<br>Second Half<br> 70<br>',
        );

        $this->assertSame(
            "Japan Rugby First Half:\n 12\nSecond Half\n 70",
            $cleaned,
        );
    }

    public function test_drops_hollow_period_scaffolding_without_scores(): void
    {
        $this->assertNull(
            SportsResultText::clean('Japan Rugby First Half:<br> <br>Second Half<br> <br>Overtime<br>'),
        );
    }
}
