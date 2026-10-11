<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name', 'activity_log');

        if (! Schema::connection($connection)->hasTable($tableName)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($tableName, 'company_id')) {
            Schema::connection($connection)->table($tableName, function (Blueprint $table) {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->index(['company_id', 'created_at']);
                $table->index(['company_id', 'log_name']);
                $table->index(['company_id', 'causer_type', 'causer_id']);
            });
        }

        $this->backfillCompanyIds($connection, $tableName);
    }

    protected function backfillCompanyIds(?string $connection, string $tableName): void
    {
        if (! Schema::connection($connection)->hasTable('companies')) {
            return;
        }

        $validCompanyIds = DB::connection($connection)->table('companies')->pluck('id')->all();
        if (empty($validCompanyIds)) {
            return;
        }

        $validCompanyLookup = array_fill_keys(array_map('intval', $validCompanyIds), true);

        // Chunk through activity_log rows where company_id is null
        DB::connection($connection)->table($tableName)
            ->whereNull('company_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($connection, $tableName, $validCompanyLookup) {
                foreach ($rows as $row) {
                    $hints = [];

                    // 1. Check subject if subject_type and subject_id exist
                    if (! empty($row->subject_type) && ! empty($row->subject_id)) {
                        if ($row->subject_type === 'App\\Models\\Core\\Company' || $row->subject_type === 'App\\Models\\Company') {
                            $candidate = (int) $row->subject_id;
                            if (isset($validCompanyLookup[$candidate])) {
                                $hints['subject'] = $candidate;
                            }
                        } elseif (class_exists($row->subject_type)) {
                            try {
                                $subjectModel = new $row->subject_type();
                                $subjectTable = $subjectModel->getTable();
                                if ($subjectTable === 'companies') {
                                    $candidate = (int) $row->subject_id;
                                    if (isset($validCompanyLookup[$candidate])) {
                                        $hints['subject'] = $candidate;
                                    }
                                } elseif (Schema::connection($connection)->hasTable($subjectTable) && Schema::connection($connection)->hasColumn($subjectTable, 'company_id')) {
                                    $candidate = DB::connection($connection)->table($subjectTable)->where('id', $row->subject_id)->value('company_id');
                                    if ($candidate && isset($validCompanyLookup[(int) $candidate])) {
                                        $hints['subject'] = (int) $candidate;
                                    }
                                }
                            } catch (Throwable) {
                                // ignore missing table or instantiation failure
                            }
                        }
                    }

                    // 2. Check causer if User
                    if (! empty($row->causer_type) && ! empty($row->causer_id)) {
                        if ($row->causer_type === 'App\\Models\\User' || is_subclass_of($row->causer_type, 'Illuminate\\Foundation\\Auth\\User')) {
                            $candidate = DB::connection($connection)->table('users')->where('id', $row->causer_id)->value('company_id');
                            if ($candidate && isset($validCompanyLookup[(int) $candidate])) {
                                $hints['causer'] = (int) $candidate;
                            }
                        }
                    }

                    // 3. Check properties JSON for company_id
                    if (! empty($row->properties)) {
                        $properties = is_string($row->properties) ? json_decode($row->properties, true) : (array) $row->properties;
                        if (is_array($properties) && ! empty($properties['company_id'])) {
                            $candidate = (int) $properties['company_id'];
                            if (isset($validCompanyLookup[$candidate])) {
                                $hints['properties'] = $candidate;
                            }
                        }
                    }

                    // Only backfill when company is proven and unambiguous.
                    // Ambiguous or conflicting rows are quarantined with company_id = null.
                    $uniqueCandidates = array_values(array_unique(array_filter($hints)));
                    if (count($uniqueCandidates) === 1) {
                        DB::connection($connection)->table($tableName)->where('id', $row->id)->update(['company_id' => $uniqueCandidates[0]]);
                    }
                }
            });
    }

    public function down(): void
    {
        $connection = config('activitylog.database_connection');
        $tableName = config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasTable($tableName) && Schema::connection($connection)->hasColumn($tableName, 'company_id')) {
            Schema::connection($connection)->table($tableName, function (Blueprint $table) {
                $table->dropForeign(['company_id']);
                $table->dropIndex(['company_id', 'created_at']);
                $table->dropIndex(['company_id', 'log_name']);
                $table->dropIndex(['company_id', 'causer_type', 'causer_id']);
                $table->dropColumn('company_id');
            });
        }
    }
};
