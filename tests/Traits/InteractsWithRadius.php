<?php

namespace Tests\Traits;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An in-memory SQLite stand-in for the FreeRADIUS database: the stock table and column names that
 * RadiusDirectory uses, created per test, with the installation switch on. Nothing here reaches a real server.
 *
 *   $this->fakeRadius();
 *   $this->seedRadiusSession(['username' => 'budi@acme', 'nasipaddress' => '192.0.2.10']);
 *   $this->radiusRows('radcheck', ['username' => 'budi@acme']);
 */
trait InteractsWithRadius
{
    /** Point the `radius` connection at a fresh SQLite database, create the tables and switch RADIUS on. */
    protected function fakeRadius(): void
    {
        config([
            'database.connections.radius' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'services.radius.enabled' => true,
        ]);
        DB::purge('radius');

        $schema = Schema::connection('radius');
        foreach (['radcheck', 'radreply'] as $table) {
            $schema->create($table, function (Blueprint $t): void {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->string('op', 2)->default('==');
                $t->string('value', 253)->default('');
            });
        }
        $schema->create('radgroupreply', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('groupname', 64)->default('')->index();
            $t->string('attribute', 64)->default('');
            $t->string('op', 2)->default('=');
            $t->string('value', 253)->default('');
        });
        $schema->create('radusergroup', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('username', 64)->default('')->index();
            $t->string('groupname', 64)->default('');
            $t->integer('priority')->default(1);
        });
        $schema->create('nas', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('nasname', 128)->index();
            $t->string('shortname', 32)->nullable();
            $t->string('type', 30)->default('other');
            $t->integer('ports')->nullable();
            $t->string('secret', 60)->default('secret');
            $t->string('server', 64)->nullable();
            $t->string('community', 50)->nullable();
            $t->string('description', 200)->default('RADIUS Client');
        });
        $schema->create('radacct', function (Blueprint $t): void {
            $t->bigIncrements('radacctid');
            $t->string('acctsessionid', 64)->default('');
            $t->string('acctuniqueid', 32)->unique();
            $t->string('username', 64)->default('')->index();
            $t->string('nasipaddress', 15)->default('')->index();
            $t->dateTime('acctstarttime')->nullable()->index();
            $t->dateTime('acctupdatetime')->nullable();
            $t->dateTime('acctstoptime')->nullable()->index();
            $t->bigInteger('acctinputoctets')->nullable();
            $t->bigInteger('acctoutputoctets')->nullable();
            $t->string('framedipaddress', 15)->default('');
        });
    }

    protected function radiusConnection(): Connection
    {
        return DB::connection('radius');
    }

    /**
     * An open session that started and updated a minute ago; override any radacct column.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function seedRadiusSession(array $attributes = []): void
    {
        static $n = 0;
        $n++;

        $this->radiusConnection()->table('radacct')->insert($attributes + [
            'acctsessionid' => "sess-$n",
            'acctuniqueid' => "uniq-$n",
            'username' => 'budi@acme',
            'nasipaddress' => '192.0.2.10',
            'acctstarttime' => now()->subMinutes(2)->format('Y-m-d H:i:s'),
            'acctupdatetime' => now()->subMinute()->format('Y-m-d H:i:s'),
            'acctstoptime' => null,
            'acctinputoctets' => 0,
            'acctoutputoctets' => 0,
            'framedipaddress' => '198.51.100.7',
        ]);
    }

    /**
     * @param  array<string, mixed>  $where
     * @return list<array<string, mixed>>
     */
    protected function radiusRows(string $table, array $where = []): array
    {
        return $this->radiusConnection()->table($table)->where($where)->orderBy($table === 'radacct' ? 'radacctid' : 'id')->get()
            ->map(fn (object $row): array => (array) $row)->all();
    }
}
