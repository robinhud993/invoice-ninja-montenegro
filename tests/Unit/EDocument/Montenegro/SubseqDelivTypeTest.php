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
use App\Services\EDocument\Standards\Montenegro\Enums\SubseqDelivType;

class SubseqDelivTypeTest extends TestCase
{
    public function testInternetOutageAllowsTwoDays(): void
    {
        $this->assertSame(2, SubseqDelivType::NOINTERNET->retryWindowDays());
    }

    public function testServiceAndOtherFailuresAllowFiveDays(): void
    {
        $this->assertSame(5, SubseqDelivType::SERVICE->retryWindowDays());
        $this->assertSame(5, SubseqDelivType::BOUNDBOOK->retryWindowDays());
        $this->assertSame(5, SubseqDelivType::TECHNICALERROR->retryWindowDays());
    }

    public function testFromSettingIsCaseInsensitiveAndNullable(): void
    {
        $this->assertSame(SubseqDelivType::NOINTERNET, SubseqDelivType::fromSetting('nointernet'));
        $this->assertSame(SubseqDelivType::SERVICE, SubseqDelivType::fromSetting(' SERVICE '));
        $this->assertNull(SubseqDelivType::fromSetting(null));
        $this->assertNull(SubseqDelivType::fromSetting('unknown'));
    }
}
