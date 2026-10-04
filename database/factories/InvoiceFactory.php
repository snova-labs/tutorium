<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invoice> */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'number' => 'INV-'.str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'quantity' => 50,
            'currency' => 'EUR',
            'unit_price_minor' => 200,
            'subtotal_minor' => 10_000,
            'total_minor' => 10_000,
            'status' => Invoice::ISSUED,
            'issued_at' => now(),
        ];
    }
}
