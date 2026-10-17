<?php

use App\Support\Database\PublicIdColumn;
use Illuminate\Database\Migrations\Migration;

/**
 * Opaque ids (spec .scratch/opaque-ids K2): every core table whose rows a URL, an API path or a payload names gets a
 * unique ULID `public_id`; each module adds its own tables in its own migration. The integer `id` stays the primary
 * key and every foreign key. Package tables (roles, media, activity_log, personal_access_tokens) fill the column
 * through creating hooks in AppServiceProvider.
 */
return new class() extends Migration
{
    public function up(): void
    {
        PublicIdColumn::add($this->tables());
    }

    public function down(): void
    {
        PublicIdColumn::drop($this->tables());
    }

    /** @return list<string> */
    private function tables(): array
    {
        return [
            'companies',
            'users',
            (string) config('permission.table_names.roles', 'roles'),
            'media',
            (string) config('activitylog.table_name', 'activity_log'),
            'personal_access_tokens',
            'device_tokens',
            'platform_admins',
            'tenant_exports',
            'customers',
            'customer_addresses',
            'customer_contacts',
            'document_types',
            'employee_evaluations',
            'employee_profiles',
            'locations',
            'number_sequences',
            'organization_units',
            'service_subscriptions',
            'vehicles',
            'whatsapp_messages',
            'whatsapp_inbound_receipts',
        ];
    }
};
