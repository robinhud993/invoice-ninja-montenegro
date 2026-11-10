<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montenegro_fiscal_counters', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_ordinal')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montenegro_fiscal_counters');
    }
};
