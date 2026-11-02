<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stock Receive, Stock Issue and Stock Adjustment share one pair of tables.
        Schema::create('stock_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind'); // receive, issue, adjustment
            $table->string('reference')->unique();
            $table->timestamp('date');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            // Stock Issue: where the goods go.
            $table->foreignUuid('to_warehouse_id')->nullable()->constrained('warehouses');
            $table->string('supplier')->default('');
            $table->string('status')->default('completed');
            $table->bigInteger('total')->default(0);
            $table->text('note')->nullable();
            $table->string('attachment')->default('');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['kind', 'date']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('stock_documents')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products');
            $table->string('code')->default('');
            $table->string('name');
            $table->string('unit')->default('');
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('price')->default(0);
            $table->string('type')->nullable(); // adjustments: addition / subtraction
            $table->string('description')->default('');
            $table->date('expiry')->nullable();
        });

        Schema::create('registers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->string('status')->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('cash_in_hand')->default(0);
            $table->text('open_note')->nullable();
            $table->bigInteger('counted_riel')->default(0);
            $table->decimal('counted_usd', 12, 2)->default(0);
            $table->bigInteger('counted_total')->default(0);
            $table->integer('card_slips')->default(0);
            $table->bigInteger('expected_cash')->default(0);
            $table->bigInteger('difference')->default(0);
            $table->bigInteger('total_sales')->default(0);
            $table->integer('invoices')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        $saleColumns = function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();
            $table->timestamp('date');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->bigInteger('total')->default(0);
            $table->bigInteger('order_tax')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('shipping')->default(0);
            $table->bigInteger('grand_total')->default(0);
            $table->bigInteger('paid')->default(0);
            $table->string('payment_status')->default('paid');
            $table->string('status')->default('completed');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
        };

        Schema::create('sales', function (Blueprint $table) use ($saleColumns) {
            $saleColumns($table);
            $table->foreignUuid('member_card_id')->nullable()->constrained('member_cards');
            $table->foreignUuid('register_id')->nullable()->constrained('registers');
            $table->foreignUuid('device_id')->nullable()->constrained('devices');
            $table->bigInteger('returned')->default(0);
            $table->string('payment_method')->default('Cash');
            // Offline sales that need a manager to look at (e.g. stock went negative).
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->default('');
            $table->timestamps();
            $table->softDeletes();
            $table->index('date');
        });

        Schema::create('sale_returns', function (Blueprint $table) use ($saleColumns) {
            $saleColumns($table);
            $table->foreignUuid('sale_id')->nullable()->constrained('sales');
            $table->timestamps();
            $table->softDeletes();
            $table->index('date');
        });

        foreach (['sale_lines' => 'sales', 'sale_return_lines' => 'sale_returns'] as $name => $parent) {
            Schema::create($name, function (Blueprint $table) use ($parent) {
                $table->id();
                $table->foreignUuid('document_id')->constrained($parent)->cascadeOnDelete();
                $table->foreignUuid('product_id')->constrained('products');
                $table->string('code')->default('');
                $table->string('name');
                $table->string('unit')->default('');
                $table->decimal('quantity', 12, 3);
                // Copied at sale time so old invoices never change.
                $table->bigInteger('price');
            });
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method');
            $table->bigInteger('amount');
            $table->bigInteger('tendered_riel')->default(0);
            $table->decimal('tendered_usd', 12, 2)->default(0);
            $table->decimal('rate', 14, 4)->default(0);
            $table->timestamp('paid_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
        });

        Schema::create('suspended_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->string('note')->default('');
            $table->json('items');
            $table->json('adjustments')->nullable();
            $table->bigInteger('total')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['suspended_sales', 'payments', 'sale_return_lines', 'sale_lines', 'sale_returns', 'sales', 'registers', 'stock_document_lines', 'stock_documents'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
