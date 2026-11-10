<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the remaining fields the Tax Administration portal (SEP / PU) records for
 * a fiscal device, so the ENU registry mirrors the portal: device type, sales
 * type, default payment method, the device name used for fiscalization, and the
 * validity window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('montenegro_enus', function (Blueprint $table) {
            $table->string('device_type', 20)->nullable()->after('name');
            $table->string('sales_type', 30)->nullable()->after('device_type');
            $table->string('payment_method', 30)->nullable()->after('sales_type');
            $table->string('tcr_internal_id', 50)->nullable()->after('payment_method');
            $table->date('valid_from')->nullable()->after('maintainer_code');
            $table->date('valid_to')->nullable()->after('valid_from');
        });
    }

    public function down(): void
    {
        Schema::table('montenegro_enus', function (Blueprint $table) {
            $table->dropColumn([
                'device_type',
                'sales_type',
                'payment_method',
                'tcr_internal_id',
                'valid_from',
                'valid_to',
            ]);
        });
    }
};
