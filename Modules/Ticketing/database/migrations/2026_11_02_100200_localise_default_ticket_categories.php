<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * The five categories every company was seeded with, by code: the old English name, the Indonesian name and the
     * tip the customer app shows. A row a company already renamed or gave its own tip is left alone.
     *
     * @var array<string, array{string, string, string}>
     */
    private const DEFAULTS = [
        'no_internet' => ['No Internet', 'Internet mati', 'Periksa lampu modem. Cabut adaptor 10 detik lalu pasang lagi. Bila lampu LOS merah, kirim laporan ini.'],
        'slow_connection' => ['Slow Connection', 'Koneksi lambat', 'Coba dengan satu perangkat di dekat modem. Hentikan unduhan besar. Bila tetap lambat, sebutkan jam kejadiannya.'],
        'packet_loss' => ['Packet Loss', 'Koneksi putus-sambung', 'Catat jam saat koneksi terputus dan berapa lama. Hidupkan ulang modem sekali sebelum melapor.'],
        'device_issue' => ['Device Issue', 'Masalah perangkat', 'Sebutkan perangkat yang bermasalah (modem atau router) dan lampu yang menyala.'],
        'fiber_issue' => ['Fiber Issue', 'Kabel fiber bermasalah', 'Jangan menarik atau menekuk kabel. Kirim foto kabel yang rusak bila bisa.'],
    ];

    public function up(): void
    {
        foreach (self::DEFAULTS as $code => [$english, $indonesian, $hint]) {
            DB::table('ticket_categories')
                ->where('code', $code)
                ->where('name', $english)
                ->update(['name' => $indonesian, 'updated_at' => now()]);

            DB::table('ticket_categories')
                ->where('code', $code)
                ->whereNull('customer_hint')
                ->update(['customer_hint' => $hint]);
        }
    }

    public function down(): void
    {
        foreach (self::DEFAULTS as $code => [$english, $indonesian, $hint]) {
            DB::table('ticket_categories')
                ->where('code', $code)
                ->where('name', $indonesian)
                ->update(['name' => $english, 'updated_at' => now()]);

            DB::table('ticket_categories')
                ->where('code', $code)
                ->where('customer_hint', $hint)
                ->update(['customer_hint' => null]);
        }
    }
};
