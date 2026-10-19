<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\NetworkAsset\Support\PortTemplateCatalogue;

/**
 * Data only. A later edit of database/data/port_templates.json ships as one more migration that calls sync().
 * Rolling back deletes the catalogue rows; assets keep their snapshot and their template_key becomes null.
 */
return new class() extends Migration
{
    public function up(): void
    {
        PortTemplateCatalogue::sync();
    }

    public function down(): void
    {
        DB::table('port_templates')->whereIn('key', PortTemplateCatalogue::keys())->delete();
    }
};
