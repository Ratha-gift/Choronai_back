<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A transfer that arrived short: the receiving branch reports it, and what
 * never arrived goes back to the sending warehouse as a Stock Receive that
 * points at the transfer (short_of).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->foreignUuid('short_of')->nullable()->constrained('stock_documents');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropForeign(['short_of']);
            $table->dropColumn('short_of');
        });
    }
};
