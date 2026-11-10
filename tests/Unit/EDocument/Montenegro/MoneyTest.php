<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit\EDocument\Montenegro;

use Tests\TestCase;
use App\Services\EDocument\Standards\Montenegro\Support\Money;

class MoneyTest extends TestCase
{
    public function testDecimalFormatsToTwoPlaces(): void
    {
        $this->assertSame('20.00', Money::decimal(20));
        $this->assertSame('212.12', Money::decimal(212.12));
        $this->assertSame('-45.50', Money::decimal(-45.5));
    }

    public function testDecimalZeroIsSerialisedAsBareZero(): void
    {
        $this->assertSame('0', Money::decimal(0));
        $this->assertSame('0', Money::decimal(0.001));
    }

    public function testQuantityAllowsUpToThreeDecimals(): void
    {
        $this->assertSame('1', Money::quantity(1.0));
        $this->assertSame('3.5', Money::quantity(3.5));
        $this->assertSame('3.125', Money::quantity(3.125));
        $this->assertSame('0', Money::quantity(0));
        $this->assertSame('-0.375', Money::quantity(-0.375));
    }
}
