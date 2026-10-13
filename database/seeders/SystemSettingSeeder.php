<?php

namespace Database\Seeders;

use App\Models\Core\Setting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'app.name', 'value' => 'inbils', 'group' => 'app', 'type' => 'string', 'is_public' => true],
            ['key' => 'app.locale', 'value' => 'id', 'group' => 'app', 'type' => 'string', 'is_public' => true],
            ['key' => 'default_currency', 'value' => 'IDR', 'group' => 'default', 'type' => 'string', 'is_public' => true],
            ['key' => 'default_currency_symbol', 'value' => 'Rp', 'group' => 'default', 'type' => 'string', 'is_public' => true],
            ['key' => 'default_timezone', 'value' => 'Asia/Jakarta', 'group' => 'default', 'type' => 'string', 'is_public' => true],
            ['key' => 'default_date_format', 'value' => 'd M Y', 'group' => 'default', 'type' => 'string', 'is_public' => true],
            ['key' => 'default_tax_ppn_rate', 'value' => '11', 'group' => 'default', 'type' => 'string', 'is_public' => false],
            ['key' => 'registration_disabled', 'value' => 'true', 'group' => 'system', 'type' => 'boolean', 'is_public' => false],
            ['key' => 'mail.from_name', 'value' => 'inbils', 'group' => 'mail', 'type' => 'string', 'is_public' => false],
            ['key' => 'mail.from_address', 'value' => 'noreply@inbils.test', 'group' => 'mail', 'type' => 'string', 'is_public' => false],
        ];

        foreach ($settings as $setting) {
            Setting::query()->updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        // Where maps open when a company picked no region (all of Indonesia); owner-edited values survive a re-seed.
        foreach ([
            ['key' => 'default_map_lat', 'value' => '-2.5489', 'type' => 'string'],
            ['key' => 'default_map_lng', 'value' => '118.0149', 'type' => 'string'],
            ['key' => 'default_map_zoom', 'value' => '5', 'type' => 'integer'],
        ] as $setting) {
            Setting::query()->firstOrCreate(
                ['key' => $setting['key']],
                $setting + ['group' => 'default', 'is_public' => true]
            );
        }

        // Owner-edited listing URLs: a re-seed must not blank them.
        foreach (['technician', 'customer'] as $role) {
            Setting::query()->firstOrCreate(
                ['key' => 'play_store_url_'.$role],
                ['value' => '', 'group' => 'system', 'type' => 'string', 'is_public' => false]
            );
        }
    }
}
