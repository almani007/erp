<?php

declare(strict_types=1);

namespace Integration\Application;

use Catalog\Application\SaveProduct;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;

/**
 * Spreadsheet (CSV) import of products, customers and suppliers, for moving
 * over from another system. A dry run checks every row and reports problems
 * without saving; a real run saves the good rows and reports the rest.
 * Products already present (same SKU) are updated, not duplicated.
 */
final class ImportData
{
    public const array COLUMNS = [
        'products' => ['sku', 'name', 'type', 'unit', 'category', 'brand', 'sale_price', 'cost_price', 'tax_code', 'barcode', 'hs_code', 'reorder_level', 'wholesale_price'],
        'customers' => ['name', 'phone', 'email', 'tax_no', 'address', 'province', 'credit_limit', 'price_tier', 'payment_days'],
        'suppliers' => ['name', 'contact_person', 'phone', 'email', 'tax_no', 'address', 'payment_terms_days'],
    ];

    public function __construct(
        private readonly SaveProduct $saveProduct,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{rows: int, saved: int, errors: list<array{row: int, message: string}>}
     */
    public function run(string $entity, string $path, bool $dryRun): array
    {
        $handle = fopen($path, 'rb');
        $header = array_map(static fn ($h): string => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), fgetcsv($handle) ?: []);
        if (! in_array('name', $header, true)) {
            throw new \DomainException('The first row must hold column names, including "name". Expected: '.implode(', ', self::COLUMNS[$entity]).'.');
        }

        $rows = $saved = 0;
        $errors = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, static fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $rows++;
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $row = array_map(static fn ($v): ?string => ($v = trim((string) $v)) === '' ? null : $v, $row);

            try {
                DB::transaction(function () use ($entity, $row, $dryRun): void {
                    match ($entity) {
                        'products' => $this->product($row),
                        'customers' => $this->customer($row),
                        'suppliers' => $this->supplier($row),
                    };
                    if ($dryRun) {
                        throw new DryRun();
                    }
                });
                $saved++;
            } catch (DryRun) {
                $saved++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $line, 'message' => $e instanceof \Illuminate\Validation\ValidationException ? implode(' ', $e->validator->errors()->all()) : $e->getMessage()];
            }
        }
        fclose($handle);

        return ['rows' => $rows, 'saved' => $dryRun ? 0 : $saved, 'valid' => $saved, 'errors' => $errors];
    }

    /** @param  array<string, string|null>  $r */
    private function product(array $r): void
    {
        Validator::make($r, [
            'sku' => ['required', 'max:80'], 'name' => ['required', 'max:200'], 'unit' => ['required'],
            'sale_price' => ['nullable', 'numeric', 'min:0'], 'cost_price' => ['nullable', 'numeric', 'min:0'],
            'type' => ['nullable', 'in:goods,service,non_stock,composite'],
        ])->validate();

        $t = $this->tenant->requireId();
        $unit = DB::table('units')->where('tenant_id', $t)->where(fn ($q) => $q->whereRaw('lower(abbreviation) = ?', [strtolower($r['unit'])])->orWhereRaw('lower(name) = ?', [strtolower($r['unit'])]))->value('id')
            ?? throw new \DomainException("Unknown unit \"{$r['unit']}\".");
        $category = $r['category'] ? $this->lookupOrCreate('product_categories', $r['category']) : null;
        $brand = ($r['brand'] ?? null) ? $this->lookupOrCreate('product_brands', $r['brand']) : null;
        $tax = ($r['tax_code'] ?? null) ? (DB::table('tax_codes')->where('tenant_id', $t)->whereRaw('lower(code) = ?', [strtolower($r['tax_code'])])->value('id')
            ?? throw new \DomainException("Unknown tax code \"{$r['tax_code']}\".")) : null;

        $existing = Product::query()->where('sku', strtoupper($r['sku']))->first();
        $data = array_filter([
            'sku' => $r['sku'], 'name' => $r['name'], 'product_type' => $r['type'] ?? 'goods', 'base_unit_id' => $unit,
            'category_id' => $category, 'brand_id' => $brand, 'tax_code_id' => $tax,
            'sale_price' => $r['sale_price'] ?? null, 'cost_price' => $r['cost_price'] ?? null, 'hs_code' => $r['hs_code'] ?? null,
            'reorder_level' => $r['reorder_level'] ?? null, 'wholesale_price' => $r['wholesale_price'] ?? null,
        ], static fn ($v) => $v !== null);
        if ($existing !== null) {
            unset($data['sku'], $data['product_type'], $data['base_unit_id']); // fixed once created
        }
        if (! empty($r['barcode'])) {
            $data['barcodes'] = array_values(array_unique(array_merge($existing?->barcodes()->pluck('barcode')->all() ?? [], [$r['barcode']])));
        }
        $this->saveProduct->execute($data, $existing);
    }

    /** @param  array<string, string|null>  $r */
    private function customer(array $r): void
    {
        Validator::make($r, [
            'name' => ['required', 'max:150'], 'email' => ['nullable', 'email'], 'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'price_tier' => ['nullable', 'in:retail,wholesale'], 'payment_days' => ['nullable', 'integer', 'min:0'],
        ])->validate();
        $t = $this->tenant->requireId();
        $existing = $r['phone'] ? Customer::query()->where('phone', $r['phone'])->first() : null;
        $fields = array_filter(array_intersect_key($r, array_flip(self::COLUMNS['customers'])), static fn ($v) => $v !== null);
        $existing !== null
            ? $existing->update($fields)
            : Customer::query()->create($fields + ['customer_code' => $this->numbers->next($t, 'customer', $t, 'CUS'), 'status' => 'active']);
    }

    /** @param  array<string, string|null>  $r */
    private function supplier(array $r): void
    {
        Validator::make($r, ['name' => ['required', 'max:150'], 'email' => ['nullable', 'email'], 'payment_terms_days' => ['nullable', 'integer', 'min:0']])->validate();
        $t = $this->tenant->requireId();
        $existing = Supplier::query()->whereRaw('lower(name) = ?', [strtolower($r['name'])])->first();
        $fields = array_filter(array_intersect_key($r, array_flip(self::COLUMNS['suppliers'])), static fn ($v) => $v !== null);
        $existing !== null
            ? $existing->update($fields)
            : Supplier::query()->create($fields + ['supplier_code' => $this->numbers->next($t, 'supplier', $t, 'SUP'), 'status' => 'active']);
    }

    private function lookupOrCreate(string $table, string $name): string
    {
        $t = $this->tenant->requireId();
        $id = DB::table($table)->where('tenant_id', $t)->whereRaw('lower(name) = ?', [strtolower($name)])->value('id');
        if ($id !== null) {
            return $id;
        }
        $id = (string) \Illuminate\Support\Str::uuid7();
        DB::table($table)->insert(['id' => $id, 'tenant_id' => $t, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }
}

/** Rolls back a checked row during a dry run. */
final class DryRun extends \RuntimeException {}
