<?php

namespace App\Services\Core;

use App\Models\Core\Setting;
use Illuminate\Support\Facades\Cache;

class SettingService
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever('setting.'.$key, function () use ($key, $default) {
            $setting = Setting::query()->where('key', $key)->first();

            return $setting ? self::castValue($setting->value, $setting->type) : $default;
        });
    }

    public static function set(string $key, mixed $value): Setting
    {
        $type = self::typeFor($value);

        $setting = Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => self::storeValue($value, $type),
                'group' => str_contains($key, '.') ? str($key)->before('.')->toString() : 'system',
                'type' => $type,
            ]
        );

        self::flush($key);

        return $setting;
    }

    public static function flush(string $key): void
    {
        Cache::forget('setting.'.$key);
    }

    public static function flushAll(): void
    {
        Cache::flush();
    }

    private static function castValue(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => $value === null ? null : (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => $value === null ? null : json_decode($value, true),
            default => $value,
        };
    }

    private static function typeFor(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_bool($value) => 'boolean',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    private static function storeValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}
