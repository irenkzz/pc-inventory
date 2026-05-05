<?php

namespace Tests\Unit;

use App\Support\InventoryFormat;
use App\Support\InventoryTime;
use Tests\TestCase;

class InventoryFormatTest extends TestCase
{
    public function test_indonesian_regional_number_and_datetime_formatting(): void
    {
        config([
            'app.locale' => 'id_ID',
            'app.timezone' => 'Asia/Jayapura',
            'inventory.display_locale' => 'id_ID',
            'inventory.display_timezone' => 'Asia/Jayapura',
            'inventory.display_timezone_label' => 'WIT',
            'inventory.display_datetime_format' => null,
            'inventory.display_decimal_separator' => null,
            'inventory.display_thousands_separator' => null,
        ]);

        $this->assertSame('1.234', InventoryFormat::number(1234));
        $this->assertSame('1.234,5', InventoryFormat::decimal(1234.5, 1));
        $this->assertSame('22 Apr 2026 11:30 WIT', InventoryTime::format('2026-04-22 11:30:00'));
    }

    public function test_custom_regional_separators_can_override_locale_defaults(): void
    {
        config([
            'app.locale' => 'id_ID',
            'inventory.display_locale' => 'id_ID',
            'inventory.display_decimal_separator' => '.',
            'inventory.display_thousands_separator' => ',',
        ]);

        $this->assertSame('1,234.5', InventoryFormat::decimal(1234.5, 1));
    }

    public function test_english_indonesia_uses_indonesia_regional_settings(): void
    {
        config([
            'app.locale' => 'en_ID',
            'app.timezone' => 'Asia/Jayapura',
            'inventory.display_locale' => 'en_ID',
            'inventory.display_timezone' => 'Asia/Jayapura',
            'inventory.display_timezone_label' => 'WIT',
            'inventory.display_datetime_format' => null,
            'inventory.display_decimal_separator' => null,
            'inventory.display_thousands_separator' => null,
        ]);

        $this->assertSame('1.234', InventoryFormat::number(1234));
        $this->assertSame('1.234,5', InventoryFormat::decimal(1234.5, 1));
        $this->assertSame('22 Apr 2026 11:30 WIT', InventoryTime::format('2026-04-22 11:30:00'));
    }
}
