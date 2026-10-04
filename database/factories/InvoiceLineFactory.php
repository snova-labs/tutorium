<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvoiceLine> */
final class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'description' => 'Active learners',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'quantity' => 50,
            'unit_price_minor' => 200,
            'amount_minor' => 10_000,
        ];
    }
}
