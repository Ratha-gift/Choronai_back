<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_rewards', function (Blueprint $table) {
            $table->foreignUuid('product_id')->nullable()->constrained('products');
            $table->decimal('quantity', 12, 3)->default(1);
        });
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->foreignUuid('register_id')->nullable()->constrained('registers');
            $table->string('refund_method')->default('Cash');
            $table->boolean('restock')->default(true);
        });
        Schema::table('registers', function (Blueprint $table) {
            $table->json('closing_summary')->nullable();
        });
        Schema::create('reward_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_key')->unique();
            $table->string('kind');
            $table->foreignUuid('member_card_id')->nullable()->constrained('member_cards');
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->foreignUuid('sale_id')->nullable()->constrained('sales');
            $table->foreignUuid('reward_id')->nullable()->constrained('product_rewards');
            $table->foreignUuid('warehouse_id')->nullable()->constrained('warehouses');
            $table->integer('points');
            $table->bigInteger('spent')->default(0);
            $table->json('details')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_entries');
        Schema::table('registers', fn (Blueprint $table) => $table->dropColumn('closing_summary'));
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropForeign(['register_id']);
            $table->dropColumn(['register_id', 'refund_method', 'restock']);
        });
        Schema::table('product_rewards', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'quantity']);
        });
    }
};
