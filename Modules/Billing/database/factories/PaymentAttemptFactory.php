<?php

namespace Modules\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentAttempt;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'invoice_id' => InvoiceFactory::new(['status' => 'sent', 'total' => 100000]),
            'company_id' => fn (array $attributes) => Invoice::withoutCompany()->findOrFail($attributes['invoice_id'])->company_id,
            'order_id' => fn (array $attributes) => PaymentAttempt::newOrderId($attributes['company_id'], $attributes['invoice_id']),
            'amount' => fn (array $attributes) => Invoice::withoutCompany()->findOrFail($attributes['invoice_id'])->sisa,
            'status' => PaymentAttempt::STATUS_PENDING,
            'snap_token' => fake()->uuid(),
            'redirect_url' => fn (array $attributes) => 'https://app.sandbox.midtrans.com/snap/v4/redirection/'.$attributes['snap_token'],
            'expires_at' => now()->addHours(PaymentAttempt::TTL_HOURS),
        ];
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => ['invoice_id' => $invoice->id]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
