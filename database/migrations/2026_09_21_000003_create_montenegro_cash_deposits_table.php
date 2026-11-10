<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montenegro_cash_deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->string('tcr_code', 10);
            $table->string('operation', 10);
            $table->decimal('cash_amt', 20, 2)->default(0);
            $table->timestamp('change_datetime')->nullable();
            $table->string('fcdc', 36)->nullable()->index();
            $table->string('status', 20)->default('pending')->index();
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'change_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montenegro_cash_deposits');
    }
};
