<?php

namespace App\Console\Commands;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\Notifications\StaffRecipients;
use Illuminate\Console\Command;
use Modules\Inventory\Models\InventoryRequest;
use Modules\Inventory\Models\MaterialReturnCase;
use Modules\Inventory\Notifications\InventoryDigestNotification;

class SendInventoryDigestCommand extends Command
{
    /**
     * What each permission of `User::INVENTORY_DIGEST_PERMISSIONS` makes its holder answerable for: the line of the digest,
     * the records that count and the statuses in which they wait.
     *
     * shortcut: counts are per company and ignore the maker-checker rules, like the tab badges, so a request the reader
     * filed themselves still counts for them; narrow it per person if that misleads.
     *
     * @var array<string, array{label: string, model: class-string<InventoryRequest|MaterialReturnCase>, statuses: list<string>}>
     */
    private const SECTIONS = [
        'inventory.request.approve' => ['label' => 'Permintaan barang menunggu persetujuan', 'model' => InventoryRequest::class, 'statuses' => ['submitted']],
        'inventory.request.prepare' => ['label' => 'Permintaan barang menunggu disiapkan', 'model' => InventoryRequest::class, 'statuses' => ['approved_reserved', 'preparing']],
        'inventory.request.issue' => ['label' => 'Permintaan barang siap diserahkan', 'model' => InventoryRequest::class, 'statuses' => ['ready_for_pickup']],
        'inventory.return.receive' => ['label' => 'Pengembalian material menunggu diterima gudang', 'model' => MaterialReturnCase::class, 'statuses' => ['awaiting_receipt']],
        'inventory.return.resolve' => ['label' => 'Selisih pengembalian menunggu diselesaikan', 'model' => MaterialReturnCase::class, 'statuses' => ['exception_open']],
    ];

    protected $signature = 'inventory:send-digest';

    protected $description = 'Mail the daily warehouse digest to everyone who switched it on in their profile: what waits for them, one mail each, nothing on an empty day.';

    public function handle(): int
    {
        if (! User::query()->where('inventory_digest', true)->where('is_active', true)->exists()) {
            $this->info('Nobody switched the digest on.');

            return self::SUCCESS;
        }

        /** @var array<int, array{user: User, companies: array<int, array{company: string, lines: list<array{label: string, count: int}>}>}> $digests by user id: one mail each, whatever number of companies they work in */
        $digests = [];

        foreach (Company::query()->where('is_active', true)->orderBy('id')->get(['id', 'name']) as $company) {
            foreach ($this->waiting($company->id) as $permission => $count) {
                foreach (StaffRecipients::holding($company->id, $permission)->where('inventory_digest', true) as $user) {
                    $digests[$user->id]['user'] = $user;
                    $digests[$user->id]['companies'][$company->id]['company'] = $company->name;
                    $digests[$user->id]['companies'][$company->id]['lines'][] = ['label' => self::SECTIONS[$permission]['label'], 'count' => $count];
                }
            }
        }

        foreach ($digests as $digest) {
            $digest['user']->notify(new InventoryDigestNotification($digest['companies']));
        }

        $this->info('Digest queued for '.count($digests).' user(s).');

        return self::SUCCESS;
    }

    /**
     * How many records wait for the holders of each permission in the company; a permission with nothing waiting is left out.
     *
     * @return array<string, int>
     */
    private function waiting(int $companyId): array
    {
        $waiting = [];

        foreach (User::INVENTORY_DIGEST_PERMISSIONS as $permission) {
            ['model' => $model, 'statuses' => $statuses] = self::SECTIONS[$permission];
            $count = $model::withoutCompany()->where('company_id', $companyId)->whereIn('status', $statuses)->count();

            if ($count > 0) {
                $waiting[$permission] = $count;
            }
        }

        return $waiting;
    }
}
