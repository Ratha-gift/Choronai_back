<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a branch prints on its invoices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->text('logo')->default('');
            $table->string('address')->default('');
            $table->string('prefix')->default('');
            $table->text('invoice_footer')->default('');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['logo', 'address', 'prefix', 'invoice_footer']);
        });
    }
};
