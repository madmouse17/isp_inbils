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
    }
}
