<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('montenegro_fiscal_logs', function (Blueprint $table) {
            $table->unsignedInteger('invoice_id')->nullable()->change();
            $table->unsignedInteger('credit_id')->nullable()->after('invoice_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('montenegro_fiscal_logs', function (Blueprint $table) {
            $table->dropColumn('credit_id');
        });
    }
};
