<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('montenegro_fiscal_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('company_id')->index();
            $table->unsignedInteger('invoice_id')->index();
            $table->unsignedInteger('client_id')->nullable()->index();

            $table->string('kind', 20)->default('create');
            $table->string('status', 20)->default('pending')->index();
            $table->string('iic', 32)->nullable()->index();
            $table->string('jikr', 36)->nullable()->index();
            $table->string('document_type', 20)->nullable();
            $table->string('subseq_deliv_type', 20)->nullable();
            $table->string('request_uuid', 36)->nullable()->index();
            $table->string('response_uuid', 36)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->integer('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->decimal('total', 20, 2)->default(0);

            $table->json('request_state')->nullable();
            $table->json('response')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('montenegro_fiscal_logs');
    }
};
