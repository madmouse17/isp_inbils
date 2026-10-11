<?php

namespace Database\Seeders\Demo;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Services\Core\NumberSequenceService;
use Carbon\CarbonImmutable;
use Modules\SPK\Models\WorkOrder;
use Modules\SPK\Services\SpkService;
use Modules\Ticketing\Jobs\CheckTicketSlaBreachesJob;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Modules\Ticketing\Services\TicketService;

/** Customer and NOC tickets from every stage, the maintenance SPKs they spawn, and the planned jobs (upgrades, relocation). */
final class TicketDemo
{
    /** Priority and SLA minutes of each category: the service stores the priority it is given and urgent halves the hours. */
    private const PRIORITIES = ['no_internet' => 'urgent', 'slow_connection' => 'high', 'packet_loss' => 'high', 'device_issue' => 'medium', 'fiber_issue' => 'high'];

    private const SLA_MINUTES = ['no_internet' => 120, 'slow_connection' => 480, 'packet_loss' => 480, 'device_issue' => 720, 'fiber_issue' => 720];

    /** @var array<string, list<array{0: string, 1: string}>> */
    private const REPORTS = [
        'no_internet' => [
            ['Internet mati total sejak pagi', 'Internet tidak bisa diakses sejak pagi. Lampu PON di ONT berkedip dan lampu LOS menyala merah.'],
            ['Tidak ada koneksi setelah listrik padam', 'Setelah listrik padam semalam ONT menyala tetapi tidak ada koneksi internet. Perangkat sudah di-restart.'],
            ['Lampu LOS merah di ONT', 'Lampu LOS pada ONT menyala merah terus, semua perangkat tidak bisa terhubung ke internet.'],
            ['Internet putus saat hujan deras', 'Koneksi putus setiap hujan deras dan baru kembali setelah hujan reda.'],
        ],
        'slow_connection' => [
            ['Internet lambat di malam hari', 'Kecepatan turun drastis pukul 19.00 sampai 23.00, speedtest hanya 3 sampai 5 Mbps.'],
            ['Speedtest jauh di bawah paket', 'Speedtest lewat kabel LAN hanya sepertiga kecepatan paket, WiFi lebih lambat lagi.'],
            ['Streaming sering buffering', 'Video streaming dan meeting online sering terputus sejak minggu lalu.'],
        ],
        'packet_loss' => [
            ['Ping tinggi dan game lag', 'Ping game online naik 150 sampai 300 ms, packet loss terlihat pada ping ke 8.8.8.8.'],
            ['Video call putus-putus', 'Video call sering patah-patah, hasil ping menunjukkan request timed out berkala.'],
        ],
        'device_issue' => [
            ['ONT sering restart sendiri', 'ONT restart beberapa kali sehari dan koneksi putus tiap restart. Adaptor terasa panas.'],
            ['WiFi tidak terdeteksi', 'Sinyal WiFi dari ONT tidak muncul di perangkat, koneksi kabel LAN masih berfungsi.'],
            ['Port LAN ONT tidak berfungsi', 'Port LAN 1 sampai 3 tidak mengirim data, hanya port 4 yang berfungsi.'],
        ],
        'fiber_issue' => [
            ['Kabel dropcore putus tertimpa pohon', 'Kabel di depan rumah tertimpa dahan pohon dan putus. Internet mati sejak kejadian.'],
            ['Kabel dropcore tertarik kendaraan besar', 'Kabel dropcore di jalan terseret truk dan menggantung rendah, koneksi tidak stabil.'],
            ['Redaman tinggi di ODP', 'Redaman di ODP lebih dari -27 dBm, pelanggan beberapa kali terputus.'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const RESOLUTIONS = [
        'no_internet' => ['Konektor SC/APC kotor dan longgar, dibersihkan lalu diganti fast connector baru. Redaman kembali -19 dBm dan koneksi normal.', 'Adaptor ONT rusak, diganti adaptor baru. ONT online dan koneksi normal.'],
        'slow_connection' => ['Redaman tinggi di ODP, adaptor dibersihkan dan jalur ditata ulang. Speedtest kembali 48 Mbps dari paket 50 Mbps.', 'Kanal WiFi 2.4 GHz padat, dipindah ke 5 GHz dan kanal diatur ulang. Kecepatan sesuai paket.'],
        'packet_loss' => ['Patch cord di ONT tidak rapat, diganti. Packet loss 0% pada uji 100 ping.', 'Konektor di ODP kotor, dibersihkan. Ping stabil di bawah 10 ms.'],
        'device_issue' => ['Firmware ONT diperbarui dan perangkat direset ke pengaturan pabrik, restart berulang berhenti.', 'ONT diganti unit baru dan konfigurasi dipindahkan, perangkat lama dibawa ke gudang.'],
        'fiber_issue' => ['Kabel dropcore diganti sepanjang 35 m dengan splicing baru dan klem pengaman tambahan. Link normal.', 'Sambungan di ODP dirapikan dan konektor diganti. Redaman turun ke -20 dBm.'],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> title, description, category */
    private const NOC_REPORTS = [
        ['Alarm LOS massal di OLT Dago PON 3', 'Monitoring mendeteksi LOS pada 14 ONT di PON 3 OLT Dago secara bersamaan.', 'fiber_issue'],
        ['Link uplink POP Antapani flapping', 'Port uplink 10G POP Antapani naik turun, packet loss terlihat di grafik monitoring.', 'packet_loss'],
        ['Jadwal maintenance penggantian kartu GPON OLT Buah Batu', 'Penggantian kartu GPON slot 2 dijadwalkan malam hari, pelanggan terdampak sudah diberi tahu.', 'device_issue'],
        ['Fiber cut jalur backbone Jl. Cihampelas', 'Kabel backbone terputus akibat galian pihak ketiga. Jalur cadangan dialihkan sementara.', 'fiber_issue'],
        ['Suhu ruang POP Pusat tinggi', 'Sensor suhu ruang POP Pusat menunjukkan 34 derajat karena pendingin ruangan mati.', 'device_issue'],
        ['Audit redaman ODP jalur Antapani', 'Pengukuran berkala redaman seluruh ODP jalur Antapani 01 dan 02.', 'slow_connection'],
        ['Penggantian baterai UPS POP Dago', 'Baterai UPS POP Dago melewati umur pakai, dijadwalkan penggantian.', 'device_issue'],
        ['Kabel semrawut di tiang Jl. Ir. H. Juanda', 'Penataan ulang kabel dropcore di tiang yang menggantung dan berpotensi putus.', 'fiber_issue'],
    ];

    public static function plan(DemoContext $c): void
    {
        self::planSpotlight($c);
        self::planHistory($c);
        self::planNoc($c);
        self::planPortalTickets($c);
        self::planFieldJobs($c);

        // The scheduler sweeps for SLA breaches every fifteen minutes; the last sweep ran just before the seeding moment.
        $c->timeline->at($c->now->subMinutes(5), fn (DemoContext $c) => (new CheckTicketSlaBreachesJob($c->companyId()))->handle(), 'tickets:sla');
    }

    /** The tickets that decide which stages the demo shows. */
    private static function planSpotlight(DemoContext $c): void
    {
        $tickets = [
            ['T01', 1, 'no_internet', 'DEMO-C021', 'customer', 'open', null, 'ok'],
            ['T02', 0, 'slow_connection', 'DEMO-C001', 'staff', 'open', null, 'ok'],
            ['T03', 2, 'slow_connection', 'DEMO-C028', 'customer', 'assigned', 'tech_dedi', 'ok'],
            ['T04', 3, 'packet_loss', 'DEMO-C027', 'customer', 'assigned', 'tech_agus', 'ok', 'generated'],
            ['T05', 1, 'device_issue', 'DEMO-C030', 'sales_dewi', 'assigned', 'technician', 'ok'],
            ['T06', 4, 'fiber_issue', 'DEMO-C025', 'customer', 'progress', 'tech_yoga', 'ok', 'in_progress'],
            ['T07', 2, 'no_internet', 'DEMO-C014', 'customer', 'progress', 'tech_agus', 'ok'],
            ['T08', 5, 'packet_loss', 'DEMO-C017', 'customer', 'progress', 'tech_dedi', 'ok'],
            ['T09', 7, 'fiber_issue', 'DEMO-C011', 'customer', 'resolved', 'tech_agus', 'late', 'completed'],
            ['T10', 10, 'device_issue', 'DEMO-C019', 'staff', 'resolved', 'tech_yoga', 'late', 'completed'],
            ['T11', 8, 'slow_connection', 'DEMO-C012', 'customer', 'resolved', 'technician', 'late'],
            ['T12', 7, 'no_internet', 'DEMO-C013', 'customer', 'resolved', 'tech_dedi', 'ok'],
            ['T13', 12, 'no_internet', 'DEMO-C006', 'customer', 'closed', 'admin', 'late'],
            ['T14', 19, 'packet_loss', 'DEMO-C010', 'staff', 'closed', 'admin', 'ok'],
        ];

        foreach ($tickets as $ticket) {
            self::planTicket($c, ...$ticket);
        }
    }

    /** Resolved tickets spread over the earlier months. */
    private static function planHistory(DemoContext $c): void
    {
        $customers = CustomerDemo::activeCustomers();

        for ($i = 0; $i < 22; $i++) {
            $days = 22 + (int) round($i * 6.4) + $c->int(0, 3);
            $eligible = array_values(array_filter($customers, fn (array $customer) => $customer['days'] >= $days + 9));

            if ($eligible === []) {
                continue;
            }

            $customer = $c->pick($eligible);
            $category = $c->weighted(['no_internet' => 25, 'slow_connection' => 20, 'packet_loss' => 12, 'device_issue' => 18, 'fiber_issue' => 25]);
            $creator = $c->weighted(['customer' => 60, 'staff' => 25, 'sales_dewi' => 15]);
            $escalated = $i % 7 === 3;

            self::planTicket(
                $c,
                sprintf('H%02d', $i + 1),
                $days,
                $category,
                $customer['code'],
                $creator,
                $escalated ? 'closed' : 'resolved',
                $escalated ? 'admin' : CustomerDemo::technicianFor($customer['pop']),
                $c->chance(75) ? 'ok' : 'late',
            );
        }
    }

    /** Network-side tickets raised by NOC and handled and closed by the administrator. */
    private static function planNoc(DemoContext $c): void
    {
        foreach (self::NOC_REPORTS as $index => [$title, $description, $category]) {
            self::planTicket(
                $c,
                sprintf('N%02d', $index + 1),
                8 + $index * 19 + $c->int(0, 6),
                $category,
                null,
                $index % 2 === 0 ? 'admin' : 'manager',
                'closed',
                'admin',
                $index % 4 === 1 ? 'late' : 'ok',
                null,
                [$title, $description],
            );
        }
    }

    /** The demo customer login reports problems of its own. */
    private static function planPortalTickets(DemoContext $c): void
    {
        self::planTicket($c, 'P01', 3, 'slow_connection', null, 'customer_demo', 'assigned', 'technician', 'ok');
        self::planTicket($c, 'P02', 41, 'no_internet', null, 'customer_demo', 'resolved', 'technician', 'ok');
    }

    /**
     * @param  'generated'|'in_progress'|'completed'|null  $spawn  Stage of the maintenance SPK the ticket spawns.
     * @param  array{0: string, 1: string}|null  $text
     */
    private static function planTicket(DemoContext $c, string $key, int $days, string $category, ?string $customerCode, string $creator, string $until, ?string $handler, string $sla, ?string $spawn = null, ?array $text = null): void
    {
        $created = $c->day($days, $c->int(8, 17), $c->int(0, 59));
        $window = self::SLA_MINUTES[$category];
        $assigned = $created->addMinutes($c->int(10, 45));
        $started = $assigned->addMinutes($c->int(12, 70));
        $resolved = $created->addMinutes($sla === 'ok' ? (int) ($window * $c->int(40, 85) / 100) : (int) ($window * $c->int(135, 280) / 100));
        $resolved = $resolved->lessThanOrEqualTo($started) ? $started->addMinutes(25) : $resolved;

        $c->timeline->at($created, fn (DemoContext $c) => self::create($c, $key, $category, $customerCode, $creator, $text), "ticket {$key} create");

        if ($until === 'open') {
            self::scheduleSlaSweep($c, $created, $window);

            return;
        }

        $c->timeline->at($assigned, fn (DemoContext $c) => self::assign($c, $key, (string) $handler), "ticket {$key} assign");
        $c->timeline->at($assigned->addMinutes(3), fn (DemoContext $c) => TicketService::addComment(self::ticket($c, $key), ['body' => 'Prioritas penanganan sesuai SLA kategori. Mohon dikabari bila membutuhkan material tambahan.', 'is_internal' => true], $c->user('manager')->id), "ticket {$key} internal note");

        if ($until === 'assigned') {
            if ($spawn === 'generated') {
                $c->timeline->at($assigned->addMinutes(20), fn (DemoContext $c) => self::spawnJob($c, $key), "ticket {$key} spawn job");
            }

            self::scheduleSlaSweep($c, $created, $window);

            return;
        }

        $c->timeline->at($started, fn (DemoContext $c) => self::start($c, $key, (string) $handler), "ticket {$key} start");

        if ($spawn !== null) {
            $jobDone = self::planSpawnedJob($c, $key, (string) $handler, $category, $started, $spawn);
            $resolved = $spawn === 'completed' ? $jobDone->addMinutes(40) : $resolved;
        }

        if ($until === 'progress') {
            self::scheduleSlaSweep($c, $created, $window);

            return;
        }

        $c->timeline->at($resolved, fn (DemoContext $c) => self::resolve($c, $key, (string) $handler, $category, $creator), "ticket {$key} resolve");

        if ($resolved->greaterThan($created->addMinutes($window))) {
            self::scheduleSlaSweep($c, $created, $window);
        }

        if ($until === 'closed') {
            // Maker/checker: the handler who resolved the ticket never closes it.
            $closer = $handler === 'manager' ? 'admin' : 'manager';
            $c->timeline->at($resolved->addHours(2), fn (DemoContext $c) => $c->as($closer, fn () => TicketService::close(self::ticket($c, $key))), "ticket {$key} close");
        }
    }

    /** The sweep that finds a ticket past its deadline runs on the first quarter hour after it. */
    private static function scheduleSlaSweep(DemoContext $c, CarbonImmutable $created, int $window): void
    {
        $deadline = $created->addMinutes($window);
        $sweep = $deadline->startOfHour()->addMinutes((int) ceil(($deadline->minute + 1) / 15) * 15);

        $c->timeline->at($sweep, fn (DemoContext $c) => (new CheckTicketSlaBreachesJob($c->companyId()))->handle(), 'tickets:sla');
    }

    private static function ticket(DemoContext $c, string $key): Ticket
    {
        return Ticket::query()->findOrFail($c->get('ticket', $key));
    }

    /** @param array{0: string, 1: string}|null $text */
    private static function create(DemoContext $c, string $key, string $category, ?string $customerCode, string $creator, ?array $text): void
    {
        [$title, $description] = $text ?? $c->pick(self::REPORTS[$category]);
        $data = [
            'title' => $title,
            'description' => $description,
            'source' => 'customer',
            'priority' => self::PRIORITIES[$category],
            'category_id' => TicketCategory::query()->where('code', $category)->firstOrFail()->id,
        ];

        $actor = $creator;

        if ($customerCode !== null) {
            $customer = Customer::query()->findOrFail($c->get('customer', $customerCode));
            $subscription = ServiceSubscription::query()->findOrFail($c->get('subscription', $customerCode));
            $data += [
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'network_asset_id' => in_array($category, ['no_internet', 'device_issue'], true) ? $subscription->ont_asset_id : null,
                'location_id' => $c->get('location', $c->get('pop', $customerCode)),
            ];
            $actor = $creator === 'customer' ? User::query()->findOrFail($customer->user_id) : $creator;
        } elseif ($creator === 'customer_demo') {
            $actor = 'customer';
        } else {
            $data['source'] = $creator === 'admin' ? 'noc' : 'internal';
        }

        $ticket = $c->as($actor, fn (User $user) => TicketService::create($data, $user->id));
        $c->put('ticket', $key, $ticket->id);
    }

    private static function assign(DemoContext $c, string $key, string $handler): void
    {
        $c->as('manager', function (User $manager) use ($c, $key, $handler): void {
            $ticket = self::ticket($c, $key);

            TicketService::assign($ticket, $c->user($handler)->id, $manager->id, null, $ticket->updated_at->utc()->format('Y-m-d\TH:i:s.v\Z'));
        });
    }

    private static function start(DemoContext $c, string $key, string $handler): void
    {
        $c->as($handler, function (User $user) use ($c, $key): void {
            TicketService::startWork(self::ticket($c, $key));
            TicketService::addComment(self::ticket($c, $key), ['body' => $c->pick([
                'Pelanggan sudah dihubungi, teknisi menuju lokasi.',
                'Pengecekan redaman dan status ONT dari OLT sedang dilakukan.',
                'Perangkat pelanggan sudah di-restart, kami pantau kestabilan koneksinya.',
            ]), 'is_internal' => false], $user->id);
        });
    }

    private static function resolve(DemoContext $c, string $key, string $handler, string $category, string $creator): void
    {
        $c->as($handler, fn () => TicketService::resolve(self::ticket($c, $key), $c->pick(self::RESOLUTIONS[$category])));

        $ticket = self::ticket($c, $key);

        if ($creator === 'customer' && $ticket->customer_id !== null && $c->chance(60)) {
            $author = User::query()->findOrFail(Customer::query()->findOrFail($ticket->customer_id)->user_id);
            TicketService::addComment($ticket, ['body' => 'Terima kasih, internet sudah normal kembali.', 'is_internal' => false], $author->id);
        }
    }

    /**
     * The manager turns the ticket into a maintenance SPK, dispatched to the ticket's handler as far as
     * $stage says. Returns the moment the job is approved (the last step planned).
     */
    private static function planSpawnedJob(DemoContext $c, string $key, string $handler, string $category, CarbonImmutable $started, string $stage): CarbonImmutable
    {
        $chain = $c->timeline->chain($started->addMinutes(35));
        $chain->step(fn (DemoContext $c) => self::spawnJob($c, $key), "ticket {$key} spawn job");

        if ($stage === 'generated') {
            return $chain->cursor();
        }

        $chain->afterMinutes(30, fn (DemoContext $c) => SpkFlow::assign($c, "TKT-{$key}", $handler), "job {$key} assign");
        $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::start($c, "TKT-{$key}", $handler), "job {$key} start");

        if ($stage === 'in_progress') {
            return $chain->cursor();
        }

        $chain->afterHours(3, fn (DemoContext $c) => SpkFlow::finish($c, "TKT-{$key}", $handler, $category === 'fiber_issue'
            ? ['KBL-DROP-1C' => 35, 'CON-FAST-SC' => 2, 'PSV-CLAMP-DC' => 6]
            : ['CON-FAST-SC' => 2, 'PSV-SLEEVE-60' => 2]), "job {$key} finish");
        $chain->afterDays(1, fn (DemoContext $c) => SpkFlow::approve($c, "TKT-{$key}", 'admin'), "job {$key} approve");

        return $chain->cursor();
    }

    private static function spawnJob(DemoContext $c, string $key): void
    {
        $workOrder = $c->as('manager', fn () => TicketService::spawnSpk(self::ticket($c, $key)));
        $workOrder->update(['ticket_id' => $c->get('ticket', $key)]);
        $c->put('spk', "TKT-{$key}", $workOrder->id);
    }

    /** Jobs planned by staff: an upgrade that was done, one that was cancelled, an upgrade and a move still being prepared. */
    private static function planFieldJobs(DemoContext $c): void
    {
        $upgrade = $c->timeline->chain($c->day(104, 10, 0));
        $upgrade->step(fn (DemoContext $c) => self::draftJob($c, 'UPG-C002', 'DEMO-C002', 'upgrade_service', 'sales_dewi', 'Upgrade paket Home 10Mbps ke Home 20Mbps', 'Pelanggan meminta kecepatan lebih tinggi untuk kerja dari rumah.', 'low'), 'upgrade job draft');
        $upgrade->afterHours(4, fn (DemoContext $c) => $c->as('manager', fn () => SpkService::generate(SpkFlow::find($c, 'UPG-C002'))), 'upgrade job generate');
        $upgrade->afterDays(1, fn (DemoContext $c) => SpkFlow::assign($c, 'UPG-C002', 'tech_dedi'), 'upgrade job assign');
        $upgrade->afterDays(1, fn (DemoContext $c) => SpkFlow::start($c, 'UPG-C002', 'tech_dedi'), 'upgrade job start');
        $upgrade->afterHours(2, fn (DemoContext $c) => SpkFlow::finish($c, 'UPG-C002', 'tech_dedi', ['CON-FAST-SC' => 1, 'PSV-SLEEVE-60' => 1]), 'upgrade job finish');
        $upgrade->afterDays(1, fn (DemoContext $c) => SpkFlow::approve($c, 'UPG-C002', 'admin'), 'upgrade job approve');

        $cancelled = $c->timeline->chain($c->day(45, 13, 0));
        $cancelled->step(fn (DemoContext $c) => self::draftJob($c, 'UPG-C017', 'DEMO-C017', 'upgrade_service', 'sales_dewi', 'Upgrade paket Promo 20Mbps ke Home 50Mbps', 'Pelanggan menanyakan kenaikan paket setelah masa promo.', 'low'), 'cancelled job draft');
        $cancelled->afterDays(1, fn (DemoContext $c) => $c->as('manager', fn () => SpkService::generate(SpkFlow::find($c, 'UPG-C017'))), 'cancelled job generate');
        $cancelled->afterDays(2, fn (DemoContext $c) => SpkFlow::cancel($c, 'UPG-C017', 'Pelanggan membatalkan upgrade karena biaya bulanan naik'), 'cancelled job cancel');

        $c->timeline->at($c->day(10, 14, 20), fn (DemoContext $c) => self::draftJob($c, 'UPG-C011', 'DEMO-C011', 'upgrade_service', 'sales_dewi', 'Upgrade paket Home 50Mbps ke Business 100Mbps', 'Pelanggan membuka usaha percetakan di rumah dan butuh bandwidth simetris.', 'medium'), 'upgrade job draft');
        $c->timeline->at($c->day(4, 11, 40), fn (DemoContext $c) => self::draftJob($c, 'REL-C021', 'DEMO-C021', 'relocation', 'staff', 'Pindah titik instalasi ke lantai dua', 'Pelanggan merenovasi rumah, ONT dan kabel perlu dipindah ke ruang kerja baru.', 'medium'), 'relocation draft');
    }

    private static function draftJob(DemoContext $c, string $key, string $customerCode, string $type, string $by, string $title, string $description, string $priority): void
    {
        $workOrder = $c->as($by, fn (User $user) => WorkOrder::query()->create([
            'code' => NumberSequenceService::generate('spk', 'SPK', $c->companyId()),
            'type' => $type,
            'title' => "{$title} - {$customerCode}",
            'description' => $description,
            'status' => 'draft',
            'customer_id' => $c->get('customer', $customerCode),
            'subscription_id' => $c->get('subscription', $customerCode),
            'location_id' => $c->get('location', $c->get('pop', $customerCode)),
            'source' => 'manual',
            'priority' => $priority,
            'created_by' => $user->id,
        ]));

        $c->put('spk', $key, $workOrder->id);
    }
}
