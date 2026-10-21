<?php

use App\Support\PermissionCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class() extends Migration
{
    /**
     * Creates every permission of the catalogue and the eight default role templates (ADR-004). A template that
     * exists is never touched, so an operator's edits survive a later deploy. On a fresh database this is what makes
     * the templates exist after `migrate` alone, with no seeding.
     */
    public function up(): void
    {
        PermissionCatalogue::install();
    }

    /** Only the default templates go; the permissions stay, as the earlier seeding left them. */
    public function down(): void
    {
        DB::table('role_templates')->whereIn('key', array_column(PermissionCatalogue::templates(), 'key'))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
