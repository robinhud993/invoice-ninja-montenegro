<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Place the currency symbol after the amount for Euro and Montenegro.
     *
     * This produces values such as "1.000,00 €" in PDFs and the admin portal.
     * The country flag is used by the EUR branch of the admin formatter, while
     * the currency flag is the base for the PDF formatter.
     */
    public function up(): void
    {
        DB::table('currencies')
            ->where('code', 'EUR')
            ->update(['swap_currency_symbol' => true]);

        DB::table('countries')
            ->where('iso_3166_2', 'ME')
            ->update(['swap_currency_symbol' => true]);
    }

    public function down(): void
    {
        // Reference data. Keep the configured position on rollback.
    }
};
