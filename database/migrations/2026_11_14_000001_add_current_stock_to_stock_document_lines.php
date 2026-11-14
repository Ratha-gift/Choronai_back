<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            // Stock on hand before this adjustment, captured when it is saved,
            // so the detail/print shows Current Stock → Quantity → Balance.
            $table->decimal('current_stock', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_document_lines', function (Blueprint $table) {
            $table->dropColumn('current_stock');
        });
    }
};
