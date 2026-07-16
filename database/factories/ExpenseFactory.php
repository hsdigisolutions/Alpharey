<?php

namespace Database\Factories;

use App\Enums\ExpenseType;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Expense;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => ExpenseType::Factura,
            'date' => now()->toDateString(),
            'subtotal' => '100',
            'vat_rate' => null,
            'vat_amount' => '0',
            'total' => '100',
            'payment_status' => PaymentStatus::Unpaid,
        ];
    }
}
