<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Settings lists and people. Lookups that the frontend stores by name
// (category, unit, brand, customer group, price group) stay plain strings on
// the rows that use them, so the API keeps the frontend's shapes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('name_km')->default('');
            $table->string('vat')->default('');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('city')->default('');
            $table->string('country')->default('');
            $table->timestamps();
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            // One "main" warehouse; every branch has its own "branch" warehouse.
            $table->string('type')->default('branch');
            $table->foreignUuid('branch_id')->nullable()->constrained('branches');
            $table->string('price_group')->default('Default');
            $table->string('phone')->default('');
            $table->string('email')->default('');
            $table->string('address')->default('');
            $table->timestamps();
        });

        $simple = [
            'categories' => fn (Blueprint $t) => [$t->string('code')->unique(), $t->string('name'), $t->string('parent')->default('')],
            'units' => fn (Blueprint $t) => [$t->string('code')->unique(), $t->string('name'), $t->string('base_unit')->default(''), $t->string('operator')->default(''), $t->string('value')->default('')],
            'brands' => fn (Blueprint $t) => [$t->string('code')->default(''), $t->string('name')->unique(), $t->string('slug')->default(''), $t->text('description')->nullable()],
            'customer_groups' => fn (Blueprint $t) => [$t->string('name')->unique(), $t->decimal('percentage', 6, 2)->default(0)],
            'price_groups' => fn (Blueprint $t) => [$t->string('name')->unique()],
            'payment_terms' => fn (Blueprint $t) => [$t->string('description')->unique(), $t->integer('due_days')->default(0), $t->integer('due_day_discount')->default(0), $t->string('discount')->default('')],
            'currencies' => fn (Blueprint $t) => [$t->string('code')->unique(), $t->string('name'), $t->decimal('rate', 14, 4)->default(1)],
            'tax_rates' => fn (Blueprint $t) => [$t->string('name')->unique(), $t->string('code')->default(''), $t->decimal('rate', 8, 2)->default(0), $t->string('type')->default('Percentage')],
            'printers' => fn (Blueprint $t) => [$t->string('title'), $t->string('type')->default('network'), $t->string('profile')->default(''), $t->string('path')->default(''), $t->string('ip')->default(''), $t->string('port')->default('')],
            'product_rewards' => fn (Blueprint $t) => [$t->string('code')->unique(), $t->string('name'), $t->string('type')->default('standard'), $t->integer('points')->default(0)],
            'suppliers' => fn (Blueprint $t) => [$t->string('company')->default(''), $t->string('name'), $t->string('phone')->default(''), $t->string('email')->default(''), $t->string('city')->default(''), $t->string('country')->default('')],
        ];
        foreach ($simple as $name => $columns) {
            Schema::create($name, function (Blueprint $table) use ($columns) {
                $table->uuid('id')->primary();
                $columns($table);
                $table->timestamps();
            });
        }

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('company')->default('');
            $table->string('name');
            $table->string('phone')->default('')->index();
            $table->string('email')->default('');
            $table->string('customer_group')->default('General');
            $table->string('price_group')->default('Default');
            $table->string('address')->default('');
            $table->integer('award_points')->default(0);
            $table->timestamps();
        });

        Schema::create('member_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('card_no')->unique();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers');
            $table->integer('award_points')->default(0);
            $table->bigInteger('spent')->default(0);
            $table->date('expiry')->nullable();
            $table->string('created_by')->default('');
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            // "system" or "pos"; values use the keys of the frontend settings form.
            $table->string('scope')->primary();
            $table->json('values');
            $table->timestamps();
        });

        Schema::create('reference_counters', function (Blueprint $table) {
            $table->id();
            $table->string('prefix');
            $table->string('period');
            $table->integer('last_number')->default(0);
            $table->unique(['prefix', 'period']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('warehouse_id')->constrained('warehouses');
            // Goes into offline references, e.g. SALE/A1/2026/0001.
            $table->string('reference_prefix')->unique();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->nullable();
            $table->string('action');
            $table->string('entity');
            $table->string('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'devices', 'reference_counters', 'settings', 'member_cards', 'customers', 'suppliers', 'product_rewards', 'printers', 'tax_rates', 'currencies', 'payment_terms', 'price_groups', 'customer_groups', 'brands', 'units', 'categories', 'warehouses', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
