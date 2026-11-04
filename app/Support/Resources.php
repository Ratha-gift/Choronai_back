<?php

namespace App\Support;

use App\Models;

/**
 * Simple lists handled by CrudController: model, validation rules, the
 * permission needed to change them, and the columns searched by ?search=.
 * Reading a list only needs a signed-in user (the POS needs most of them).
 */
class Resources
{
    public static function all(): array
    {
        $settings = 'settings.edit';

        return [
            'branches' => [Models\Branch::class, $settings, ['name', 'city'], [
                'name' => 'required|string|max:120', 'name_km' => 'nullable|string', 'vat' => 'nullable|string',
                'phone' => 'nullable|string', 'email' => 'nullable|email', 'city' => 'nullable|string', 'country' => 'nullable|string',
            ]],
            'warehouses' => [Models\Warehouse::class, $settings, ['code', 'name'], [
                'code' => 'required|string|max:30', 'name' => 'required|string|max:120', 'type' => 'required|in:main,branch',
                'branch_id' => 'nullable|uuid|exists:branches,id', 'price_group' => 'nullable|string',
                'phone' => 'nullable|string', 'email' => 'nullable|email', 'address' => 'nullable|string',
            ]],
            'categories' => [Models\Category::class, 'settings.categories', ['code', 'name'], [
                'code' => 'required|string|max:30', 'name' => 'required|string|max:120', 'parent' => 'nullable|string',
            ]],
            'units' => [Models\Unit::class, $settings, ['code', 'name'], [
                'code' => 'required|string|max:20', 'name' => 'required|string', 'base_unit' => 'nullable|string',
                'operator' => 'nullable|in:,*,/', 'value' => 'nullable|string',
            ]],
            'brands' => [Models\Brand::class, $settings, ['code', 'name'], [
                'code' => 'nullable|string', 'name' => 'required|string', 'slug' => 'nullable|string', 'description' => 'nullable|string',
            ]],
            'customer-groups' => [Models\CustomerGroup::class, $settings, ['name'], [
                'name' => 'required|string', 'percentage' => 'numeric',
            ]],
            'price-groups' => [Models\PriceGroup::class, $settings, ['name'], ['name' => 'required|string']],
            'payment-terms' => [Models\PaymentTerm::class, $settings, ['description'], [
                'description' => 'required|string', 'due_days' => 'integer|min:0', 'due_day_discount' => 'integer|min:0', 'discount' => 'nullable|string',
            ]],
            'currencies' => [Models\Currency::class, 'settings.currencies', ['code', 'name'], [
                'code' => 'required|string|max:10', 'name' => 'required|string', 'rate' => 'required|numeric|gt:0',
            ]],
            'tax-rates' => [Models\TaxRate::class, 'settings.taxRates', ['name', 'code'], [
                'name' => 'required|string', 'code' => 'nullable|string', 'rate' => 'required|numeric|min:0', 'type' => 'required|in:Fixed,Percentage',
            ]],
            'printers' => [Models\Printer::class, $settings, ['title'], [
                'title' => 'required|string', 'type' => 'nullable|string', 'profile' => 'nullable|string',
                'path' => 'nullable|string', 'ip' => 'nullable|string', 'port' => 'nullable|string',
            ]],
            'product-rewards' => [Models\ProductReward::class, 'products.edit', ['code', 'name'], [
                'code' => 'required|string', 'name' => 'required|string', 'type' => 'nullable|string', 'points' => 'required|integer|min:0',
            ]],
            'suppliers' => [Models\Supplier::class, 'suppliers.edit', ['company', 'name', 'phone'], [
                'company' => 'nullable|string', 'name' => 'required|string', 'phone' => 'nullable|string',
                'email' => 'nullable|email', 'city' => 'nullable|string', 'country' => 'nullable|string',
            ]],
            'customers' => [Models\Customer::class, 'customers.edit', ['code', 'name', 'phone', 'company'], [
                'code' => 'nullable|string', 'company' => 'nullable|string', 'name' => 'required|string', 'phone' => 'nullable|string',
                'email' => 'nullable|email', 'customer_group' => 'nullable|string', 'price_group' => 'nullable|string', 'address' => 'nullable|string',
            ]],
            'member-cards' => [Models\MemberCard::class, 'memberCards.edit', ['card_no'], [
                'card_no' => 'required|string', 'customer_id' => 'nullable|uuid|exists:customers,id', 'expiry' => 'nullable|date',
            ]],
        ];
    }

    public static function find(string $name): array
    {
        return self::all()[$name] ?? abort(404);
    }
}
