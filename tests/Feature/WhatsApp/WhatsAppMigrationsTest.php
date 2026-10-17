<?php

namespace Tests\Feature\WhatsApp;

use App\Support\Database\PublicIdColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * MySQL DDL implicitly commits, so the test puts the schema back itself.
 */
class WhatsAppMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const CONSENT_COLUMNS = ['whatsapp_opt_in_at', 'whatsapp_opt_in_source', 'whatsapp_opt_in_by', 'whatsapp_opt_out_at'];

    private const QUOTA_INDEX = 'whatsapp_messages_quota_index';

    public function test_the_whatsapp_migrations_roll_back_cleanly_and_apply_again(): void
    {
        $messages = require database_path('migrations/2026_10_13_300001_create_whatsapp_messages_table.php');
        $consent = require database_path('migrations/2026_10_13_300002_add_whatsapp_consent_to_customers_table.php');
        $receipts = require database_path('migrations/2026_10_13_300003_create_whatsapp_inbound_receipts_table.php');
        $quota = require database_path('migrations/2026_10_13_300004_add_quota_index_to_whatsapp_messages_table.php');

        try {
            $this->assertTrue(Schema::hasTable('whatsapp_messages'));
            $this->assertTrue(Schema::hasTable('whatsapp_inbound_receipts'));
            $this->assertTrue(Schema::hasColumns('customers', self::CONSENT_COLUMNS));
            $this->assertTrue(Schema::hasIndex('whatsapp_messages', self::QUOTA_INDEX));

            $quota->down();
            $this->assertFalse(Schema::hasIndex('whatsapp_messages', self::QUOTA_INDEX));
            $this->assertTrue(Schema::hasTable('whatsapp_messages'), 'dropping the index keeps the log');

            $receipts->down();
            $consent->down();
            $messages->down();

            $this->assertFalse(Schema::hasTable('whatsapp_inbound_receipts'));
            $this->assertFalse(Schema::hasTable('whatsapp_messages'));
            foreach (self::CONSENT_COLUMNS as $column) {
                $this->assertFalse(Schema::hasColumn('customers', $column), $column);
            }
        } finally {
            if (! Schema::hasTable('whatsapp_messages')) {
                $messages->up();
            }
            if (! Schema::hasColumn('customers', 'whatsapp_opt_in_at')) {
                $consent->up();
            }
            if (! Schema::hasTable('whatsapp_inbound_receipts')) {
                $receipts->up();
            }
            if (! Schema::hasIndex('whatsapp_messages', self::QUOTA_INDEX)) {
                $quota->up();
            }
            PublicIdColumn::add(['whatsapp_messages', 'whatsapp_inbound_receipts']);
        }

        $this->assertTrue(Schema::hasTable('whatsapp_messages'));
        $this->assertTrue(Schema::hasTable('whatsapp_inbound_receipts'));
        $this->assertTrue(Schema::hasColumns('customers', self::CONSENT_COLUMNS));
        $this->assertTrue(Schema::hasIndex('whatsapp_messages', self::QUOTA_INDEX));
    }
}
