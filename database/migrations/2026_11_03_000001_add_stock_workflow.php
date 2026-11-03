<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            // Existing documents already moved stock at both ends; retain that history.
            $table->string('workflow')->default('legacy');
            $table->foreignUuid('transfer_id')->nullable()->constrained('stock_documents');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['workflow', 'transfer_id', 'approved_by', 'approved_at']);
        });
    }
};
