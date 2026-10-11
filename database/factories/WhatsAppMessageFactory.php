<?php

namespace Database\Factories;

use App\Models\Core\Company;
use App\Models\Core\WhatsAppMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhatsAppMessage>
 */
class WhatsAppMessageFactory extends Factory
{
    protected $model = WhatsAppMessage::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::query()->value('id') ?? Company::factory()->create()->id,
            'recipient_type' => 'customer',
            'recipient_id' => fake()->numberBetween(1, 99999),
            'event' => 'payment_received',
            'subject_type' => 'invoice',
            'subject_id' => fake()->numberBetween(1, 99999),
            'template' => 'pembayaran_diterima',
            'language' => 'id',
            'dedupe_key' => 'payment_received:'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'sent',
            'provider_message_id' => 'wamid.'.fake()->unique()->bothify('????????########'),
            'recipient_hash' => hash('sha256', fake()->unique()->numerify('62812########')),
            'recipient_last4' => fake()->numerify('####'),
            'sent_at' => now(),
        ];
    }

    public function skipped(string $reason): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'skip_reason' => $reason,
            'provider_message_id' => null,
            'sent_at' => null,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
