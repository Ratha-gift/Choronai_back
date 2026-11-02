<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Money is riel as whole numbers (bigint). Quantities and weights have three
// decimals (2.500 kg).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('sku')->unique();
            // Item number typed into the label scale (weight barcodes).
            $table->string('plu_code')->nullable()->unique();
            $table->string('name');
            $table->string('type')->default('standard');
            $table->string('category')->default('Other');
            $table->string('sub_category')->default('');
            $table->string('brand')->default('');
            $table->string('unit')->default('kg');
            $table->string('sale_unit')->default('');
            $table->string('purchase_unit')->default('');
            $table->bigInteger('price')->default(0);
            $table->bigInteger('cost')->default(0);
            $table->bigInteger('additional')->default(0);
            $table->decimal('alert_quantity', 12, 3)->default(0);
            $table->bigInteger('promo_price')->nullable();
            $table->date('promo_start')->nullable();
            $table->date('promo_end')->nullable();
            $table->string('image')->default('');
            $table->json('gallery')->nullable();
            $table->json('custom_fields')->nullable();
            $table->text('details')->nullable();
            $table->text('invoice_details')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('combo_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products');
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('price')->default(0);
        });

        Schema::create('product_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('supplier');
            $table->string('part_number')->default('');
            $table->bigInteger('price')->default(0);
        });

        Schema::create('price_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('name');
            $table->bigInteger('old_price');
            $table->bigInteger('new_price');
            $table->string('actor');
            $table->timestamp('at');
        });

        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->unique(['product_id', 'warehouse_id']);
        });

        // The stock ledger: every change writes one row.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('product_id')->constrained('products');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->decimal('quantity', 12, 3);
            // sale, sale_return, receive, issue_out, issue_in, adjustment
            $table->string('reason');
            $table->string('document_type');
            $table->uuid('document_id');
            $table->date('expiry')->nullable();
            $table->foreignUuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['document_type', 'document_id']);
            $table->index(['product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_movements', 'stock_levels', 'price_changes', 'product_suppliers', 'combo_items', 'products'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
