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

namespace App\Services\EDocument\Standards\Montenegro;

use App\Models\Company;
use App\Models\Credit;
use App\Models\Invoice;
use App\Models\MontenegroEnu;
use App\Services\EDocument\Standards\Montenegro\Enums\InvoiceType;
use App\Services\EDocument\Standards\Montenegro\Enums\PaymentMethod;

/**
 * FiscalSettings
 *
 * Resolves the per company EFI configuration from the company settings bag.
 *
 * The following settings keys are used. They are declared on CompanySettings so
 * they always resolve to a default:
 *
 *  - montenegro_tin                 Taxpayer TIN (PIB, 8 digits) or JMB (13 digits)
 *  - montenegro_tcr_code            ENU / TCR code assigned by CIS, e.g. cc123cc123
 *  - montenegro_business_unit_code  Business unit (premise) code assigned by CIS
 *  - montenegro_operator_code       Operator code assigned by CIS
 *  - montenegro_software_code       Software code assigned by CIS
 *  - montenegro_maintainer_code     Optional maintainer code
 *  - montenegro_is_in_vat           Whether the taxpayer is in the VAT system
 *  - montenegro_test_mode           Send to the efitest environment
 *  - montenegro_invoice_type        Default CASH or NONCASH for regular invoices
 *  - montenegro_payment_method      Default EFI payment method for regular invoices
 *  - montenegro_advance_marker      custom_value1 marker that flags an advance (avans) invoice
 */
class FiscalSettings
{
    public const SETTING_TIN = 'montenegro_tin';
    public const SETTING_TCR_CODE = 'montenegro_tcr_code';
    public const SETTING_BUSINESS_UNIT_CODE = 'montenegro_business_unit_code';
    public const SETTING_OPERATOR_CODE = 'montenegro_operator_code';
    public const SETTING_SOFTWARE_CODE = 'montenegro_software_code';
    public const SETTING_MAINTAINER_CODE = 'montenegro_maintainer_code';
    public const SETTING_IS_IN_VAT = 'montenegro_is_in_vat';
    public const SETTING_TEST_MODE = 'montenegro_test_mode';
    public const SETTING_INVOICE_TYPE = 'montenegro_invoice_type';
    public const SETTING_PAYMENT_METHOD = 'montenegro_payment_method';
    public const SETTING_ADVANCE_MARKER = 'montenegro_advance_marker';
    public const SETTING_AUTO_FISCALIZE = 'montenegro_auto_fiscalize';
    public const SETTING_REQUIRE_BUYER_TIN = 'montenegro_require_buyer_tin';
    public const SETTING_SIMPLIFIED_INVOICE = 'montenegro_simplified_invoice';
    public const SETTING_BANK_ACCOUNT = 'montenegro_bank_account';
    public const SETTING_COMP_CARD = 'montenegro_comp_card';
    public const SETTING_SELF_ISS_TYPE = 'montenegro_self_iss_type';

    /** Valid SelfIssSType values from the schema. */
    public const SELF_ISS_TYPES = ['AGREEMENT', 'DOMESTIC', 'ABROAD', 'SELF', 'OTHER'];

    private ?MontenegroEnu $resolved_enu = null;

    private bool $enu_resolved = false;

    private ?Certificate $resolved_certificate = null;

    private bool $certificate_resolved = false;

    public function __construct(
        public readonly Company $company,
        public readonly Invoice|Credit|null $invoice = null,
    ) {}

    /**
     * The ENU selected for the invoice, or the company default ENU.
     */
    private function enu(): ?MontenegroEnu
    {
        if (!$this->enu_resolved) {
            $this->resolved_enu = MontenegroEnu::defaultFor($this->company);
            $this->enu_resolved = true;
        }

        return $this->resolved_enu;
    }

    public function tin(): string
    {
        $tin = (string) $this->company->getSetting(self::SETTING_TIN);

        if ($tin !== '') {
            return $tin;
        }

        // The TIN is also carried by the signing certificate, so it can be
        // derived automatically once the certificate is uploaded instead of
        // being entered by hand.
        $certificateTin = $this->certificate()?->taxNumber();

        if ($certificateTin !== null && $certificateTin !== '') {
            return $certificateTin;
        }

        $idNumber = (string) ($this->company->getSetting('id_number') ?? '');
        $vatNumber = (string) ($this->company->getSetting('vat_number') ?? '');

        return $idNumber !== '' ? $idNumber : $vatNumber;
    }

    public function tcrCode(): string
    {
        return (string) ($this->invoice?->backup->tcr_code ?: $this->enu()?->tcr_code ?: $this->company->getSetting(self::SETTING_TCR_CODE));
    }

    public function businessUnitCode(): string
    {
        return (string) ($this->invoice?->backup->business_unit_code ?: $this->enu()?->business_unit_code ?: $this->company->getSetting(self::SETTING_BUSINESS_UNIT_CODE));
    }

    public function operatorCode(): string
    {
        return (string) ($this->invoice?->backup->operator_code ?: $this->enu()?->operator_code ?: $this->company->getSetting(self::SETTING_OPERATOR_CODE));
    }

    public function softwareCode(): string
    {
        return (string) ($this->enu()?->software_code ?: $this->company->getSetting(self::SETTING_SOFTWARE_CODE));
    }

    public function maintainerCode(): ?string
    {
        $code = (string) ($this->enu()?->maintainer_code ?: $this->company->getSetting(self::SETTING_MAINTAINER_CODE));

        return $code === '' ? null : $code;
    }

    public function isInVat(): bool
    {
        // The claimed VAT status must match the Tax Administration record
        // exactly, so it is driven solely by the explicit company switch and is
        // never inferred from the unrelated vat_number field. Inferring it
        // causes CIS error 44 (real issuer VAT status is different).
        return (bool) $this->company->getSetting(self::SETTING_IS_IN_VAT);
    }

    public function testMode(): bool
    {
        return (bool) $this->company->getSetting(self::SETTING_TEST_MODE);
    }

    public function defaultInvoiceType(): InvoiceType
    {
        $value = strtoupper((string) $this->company->getSetting(self::SETTING_INVOICE_TYPE));

        return InvoiceType::tryFrom($value) ?? InvoiceType::NONCASH;
    }

    public function defaultPaymentMethod(): PaymentMethod
    {
        return PaymentMethod::fromSetting(
            (string) $this->company->getSetting(self::SETTING_PAYMENT_METHOD),
            PaymentMethod::ACCOUNT
        );
    }

    public function advanceMarker(): string
    {
        $marker = (string) $this->company->getSetting(self::SETTING_ADVANCE_MARKER);

        return $marker === '' ? 'ADVANCE' : $marker;
    }

    /**
     * When disabled, invoices are not fiscalized automatically on issue. They
     * must be fiscalized explicitly through the fiscalize action.
     */
    public function autoFiscalize(): bool
    {
        return (bool) $this->company->getSetting(self::SETTING_AUTO_FISCALIZE);
    }

    /**
     * When enabled, a non-cash invoice cannot be fiscalized without a buyer tax
     * number (TIN), which the EFI rules require for business to business sales.
     */
    public function requireBuyerTin(): bool
    {
        return (bool) $this->company->getSetting(self::SETTING_REQUIRE_BUYER_TIN);
    }

    /**
     * When enabled, invoices are flagged as simplified invoices. Simplified
     * invoices are used for small retail sales and may omit the buyer.
     */
    public function simplifiedInvoice(): bool
    {
        return (bool) $this->company->getSetting(self::SETTING_SIMPLIFIED_INVOICE);
    }

    /**
     * Bank account reported on ACCOUNT pay methods (BankAccNum / BankAcc).
     */
    public function bankAccount(): ?string
    {
        $value = trim((string) $this->company->getSetting(self::SETTING_BANK_ACCOUNT));

        return $value === '' ? null : $value;
    }

    /**
     * Company card number reported on COMPANY pay methods (CompCard).
     */
    public function compCard(): ?string
    {
        $value = trim((string) $this->company->getSetting(self::SETTING_COMP_CARD));

        return $value === '' ? null : $value;
    }

    public function selfIssType(): ?string
    {
        $value = strtoupper(trim((string) $this->company->getSetting(self::SETTING_SELF_ISS_TYPE)));

        return in_array($value, self::SELF_ISS_TYPES, true) ? $value : null;
    }

    public function certificate(): ?Certificate
    {
        if (!$this->certificate_resolved) {
            $this->resolved_certificate = Certificate::fromCompany($this->company);
            $this->certificate_resolved = true;
        }

        return $this->resolved_certificate;
    }

    public function endpoint(): string
    {
        return $this->testMode()
            ? (string) config('services.montenegro.test_url')
            : (string) config('services.montenegro.production_url');
    }

    public function qrCode(): QrCode
    {
        return new QrCode($this->testMode());
    }

    /**
     * The settings that are mandatory before an invoice can be fiscalized.
     *
     * @return array<int, string> setting keys that are missing
     */
    public function missingRequiredSettings(): array
    {
        $required = [
            self::SETTING_TIN => $this->tin(),
            self::SETTING_TCR_CODE => $this->tcrCode(),
            self::SETTING_BUSINESS_UNIT_CODE => $this->businessUnitCode(),
            self::SETTING_OPERATOR_CODE => $this->operatorCode(),
            self::SETTING_SOFTWARE_CODE => $this->softwareCode(),
        ];

        $missing = [];

        foreach ($required as $key => $value) {
            if (trim((string) $value) === '') {
                $missing[] = $key;
            }
        }

        if ($this->certificate() === null) {
            $missing[] = $this->company->e_invoice_certificate
                ? 'e_invoice_certificate (stored but unreadable, check the passphrase)'
                : 'e_invoice_certificate';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingRequiredSettings() === [];
    }
}
