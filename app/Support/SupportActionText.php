<?php

namespace App\Support;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * What the Inbils support team did, as the one Indonesian sentence a tenant's audit log shows for a `support` row.
 * The code of such a row is its `description`: AuditService writes it there and in `event`, but `event` is empty on
 * rows from before the log-audit lane. The audit screen, its search and its export all read this dictionary, so a
 * code is worded once.
 */
final class SupportActionText
{
    private const TEAM = 'Tim dukungan Inbils';

    /** A code this dictionary does not know yet: the row still says who it came from. */
    private const UNKNOWN = 'Akses dukungan oleh tim Inbils';

    /** Code => what the team did; :name is the subject user's name ("pengguna" when the subject is not known). */
    private const SENTENCES = [
        'user.two_factor_reset' => 'mengatur ulang 2FA :name',
        'user.invitation_resent' => 'mengirim ulang undangan admin ke :name',
        'user.login_unlocked' => 'membuka kunci masuk :name',
        'impersonation.started' => 'masuk sebagai :name',
        'impersonation.mode_changed' => 'mengubah mode akses saat masuk sebagai :name',
        'impersonation.write' => 'mengubah data saat masuk sebagai :name',
        'impersonation.ended' => 'keluar dari akun :name',
        'tenant.suspended' => 'menangguhkan perusahaan ini',
        'tenant.reactivated' => 'mengaktifkan kembali perusahaan ini',
        'tenant.deletion_requested' => 'mencatat permintaan penghapusan data perusahaan',
        'tenant.export_requested' => 'meminta ekspor data perusahaan',
        'tenant.export_completed' => 'menyelesaikan ekspor data perusahaan',
        'tenant.export_downloaded' => 'mengunduh ekspor data perusahaan',
    ];

    /**
     * "Tim dukungan Inbils mengatur ulang 2FA Budi Santoso". The subject is read only when its relation is loaded,
     * so a list that forgot to load it shows "pengguna" instead of one query per row.
     */
    public static function for(Activity $row): string
    {
        $sentence = self::SENTENCES[(string) $row->description] ?? null;

        if ($sentence === null) {
            return self::UNKNOWN;
        }

        $subject = $row->relationLoaded('subject') ? $row->getRelation('subject') : null;

        return self::TEAM.' '.str_replace(':name', $subject instanceof User ? $subject->name : 'pengguna', $sentence);
    }

    /**
     * The codes whose sentence, without the person's name, contains $term: what a search for visible text means.
     * The name is matched on the subject user, not here.
     *
     * @return list<string>
     */
    public static function codesMatching(string $term): array
    {
        $needle = mb_strtolower(trim($term));

        if ($needle === '') {
            return [];
        }

        return array_keys(array_filter(
            self::SENTENCES,
            fn (string $sentence): bool => str_contains(mb_strtolower(self::TEAM.' '.trim(str_replace(':name', '', $sentence))), $needle),
        ));
    }
}
