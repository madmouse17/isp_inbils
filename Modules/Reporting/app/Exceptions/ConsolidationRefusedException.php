<?php

namespace Modules\Reporting\Exceptions;

use RuntimeException;

/** Companies in different currencies cannot be added up; the consolidated report refuses instead. */
class ConsolidationRefusedException extends RuntimeException
{
    /** @param list<string> $currencies the distinct currencies of the companies in the report */
    public function __construct(public readonly array $currencies)
    {
        parent::__construct(
            'Laporan konsolidasi ditolak: perusahaan yang dipilih memakai mata uang berbeda ('.implode(', ', $currencies).'). '
            .'Jumlah dalam mata uang berbeda tidak bisa dijumlahkan. Pilih perusahaan yang memakai satu mata uang yang sama.'
        );
    }
}
