<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montenegro_enus', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('name')->nullable();
            $table->string('tcr_code', 10);
            $table->string('business_unit_code', 10);
            $table->string('operator_code', 10);
            $table->string('software_code', 10)->nullable();
            $table->string('maintainer_code', 10)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'tcr_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montenegro_enus');
    }
};
