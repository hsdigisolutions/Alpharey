<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceSubType;
use App\Enums\InvoiceType;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'number' => 'F1-'.fake()->unique()->numberBetween(10000, 99999),
            'type' => InvoiceType::Sale,
            'sub_type' => InvoiceSubType::Final,
            'invoice_date' => now()->toDateString(),
            'subtotal' => '0',
            'vat_rate' => null, // blank default, per DECISIONS.md
            'vat_amount' => '0',
            'total' => '0',
            'paid_amount' => '0',
            'status' => InvoiceStatus::Draft,
            'payment_status' => PaymentStatus::Unpaid,
        ];
    }

    public function expense(): static
    {
        return $this->state(fn () => ['type' => InvoiceType::Expense]);
    }
}
