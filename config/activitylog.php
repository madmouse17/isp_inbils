<?php

use Spatie\Activitylog\Models\Activity;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * `inbils:activity-log:prune` (scheduled daily) deletes the activities older than this many days, for every log
     * name that is not listed in retention_days_by_log below, rows without a log name included. Spatie's own
     * `activitylog:clean` reads only this number and is never scheduled.
     */
    'delete_records_older_than_days' => 365,

    /*
     * Log names that are kept longer than the default, in days. These are the trails an incident review reads long
     * after the fact: sign-ins and role changes (security), the platform panel, and support access to a tenant.
     */
    'retention_days_by_log' => [
        'security' => 730,
        'platform' => 730,
        'support' => 730,
    ],

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject returns soft deleted models.
     */
    'subject_returns_soft_deleted_models' => false,

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     */
    'activity_model' => Activity::class,

    /*
     * This is the name of the table that will be created by the migration and
     * used by the Activity model shipped with this package.
     */
    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    /*
     * This is the database connection that will be used by the migration and
     * the Activity model shipped with this package. In case it's not set
     * Laravel's database.default will be used instead.
     */
    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
