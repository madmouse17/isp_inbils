<?php

namespace Tests\Traits;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Races independent PHP processes against the shared MySQL test database (pattern of
 * SerializedAssetConcurrencySmokeTest). The fixture must be committed first (commitFixture()) so the
 * workers see it, and removed again with purgeCompany() because RefreshDatabase can no longer roll it back.
 *
 * Every worker boots the application, signals "ready", waits for the common "go" file and then runs the
 * scenario code inside CompanyService::runAs($payload['company_id']). A scenario returns a JSON-able value;
 * a ValidationException becomes {"ok": false, "status": 422, "errors": ...} and an HTTP exception (abort(),
 * ConflictHttpException) becomes {"ok": false, "status": <code>, "message": "<text>"}. Any other exception fails the race.
 */
trait RunsConcurrentWorkers
{
    /** Commits the open RefreshDatabase transaction so other connections can read the fixture. */
    protected function commitFixture(): void
    {
        DB::commit();
    }

    /**
     * @param  list<array<string, mixed>>  $payloads  one worker each; every payload needs company_id
     * @param  string  $scenario  PHP statements with $args in scope; the value they `return` is the result
     * @return list<array{ok: bool, status?: int, result?: mixed, errors?: array<string, mixed>, message?: string}>
     */
    protected function race(array $payloads, string $scenario): array
    {
        $go = tempnam(sys_get_temp_dir(), 'race-go-');
        $this->assertNotFalse($go);
        unlink($go);

        $workers = [];

        try {
            foreach ($payloads as $payload) {
                $workers[] = $this->startRaceWorker($payload, $scenario, $go);
            }

            $deadline = microtime(true) + 20;

            foreach ($workers as $worker) {
                while (! is_file($worker['ready'])) {
                    $status = proc_get_status($worker['process']);

                    if (! $status['running']) {
                        $this->fail('A race worker exited before it was ready: '.$this->raceOutput($worker));
                    }

                    if (microtime(true) >= $deadline) {
                        $this->fail('A race worker did not become ready within 20 seconds.');
                    }

                    usleep(5000);
                }
            }

            file_put_contents($go, 'go');

            $results = [];

            foreach ($workers as $worker) {
                $results[] = $this->finishRaceWorker($worker);
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                $this->stopRaceWorker($worker);
            }

            @unlink($go);
        }
    }

    /**
     * True when $operation, run on the default connection, has to wait for the row lock a second connection holds on
     * $table#$id (that connection runs SELECT … FOR UPDATE; the default one gets innodb_lock_wait_timeout 1, so a waiting
     * operation fails with 1205). Other exceptions propagate. The fixture must be committed first (commitFixture()).
     */
    protected function waitsForRowLock(string $table, int $id, Closure $operation): bool
    {
        config(['database.connections.mysql_peer' => config('database.connections.'.DB::getDefaultConnection())]);
        $peer = DB::connection('mysql_peer');
        $timeoutBefore = (int) DB::scalar('SELECT @@SESSION.innodb_lock_wait_timeout');

        try {
            $peer->beginTransaction();
            $this->assertNotNull($peer->table($table)->where('id', $id)->lockForUpdate()->first(), "{$table}#{$id} is not visible to a second connection: commitFixture() first.");

            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $operation();

                return false;
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
                    throw $e;
                }

                return true;
            }
        } finally {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$timeoutBefore);

            if ($peer->transactionLevel() > 0) {
                $peer->rollBack();
            }

            DB::purge('mysql_peer');
        }
    }

    /** Deletes everything a committed fixture left in one company (foreign keys off: the order does not matter). */
    protected function purgeCompany(int $companyId): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            $tables = DB::table('information_schema.columns')
                ->selectRaw('DISTINCT table_name AS name')
                ->whereRaw('table_schema = DATABASE()')
                ->where('column_name', 'company_id')
                ->pluck('name');

            $userIds = DB::table('users')->where('company_id', $companyId)->pluck('id');
            DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_permissions')->where('model_type', 'App\\Models\\User')->whereIn('model_id', $userIds)->delete();

            foreach ($tables as $table) {
                DB::table($table)->where('company_id', $companyId)->delete();
            }

            DB::table('companies')->where('id', $companyId)->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{process: resource, pipes: array<int, resource>, payload: string, script: string, ready: string}
     */
    private function startRaceWorker(array $payload, string $scenario, string $go): array
    {
        $ready = tempnam(sys_get_temp_dir(), 'race-ready-');
        $this->assertNotFalse($ready);
        unlink($ready);

        $payloadFile = tempnam(sys_get_temp_dir(), 'race-payload-');
        $this->assertNotFalse($payloadFile);
        file_put_contents($payloadFile, json_encode([...$payload, 'ready' => $ready, 'go' => $go], JSON_THROW_ON_ERROR));

        $script = <<<PHP
        <?php
        \$args = json_decode(file_get_contents(getenv('RACE_WORKER_PAYLOAD')), true, 512, JSON_THROW_ON_ERROR);
        require getcwd().'/vendor/autoload.php';
        Tests\\TestCase::guardPreBootstrapEnvironment();
        \$app = require getcwd().'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        file_put_contents(\$args['ready'], '1');
        \$deadline = microtime(true) + 20;
        while (! is_file(\$args['go'])) {
            if (microtime(true) > \$deadline) { fwrite(STDERR, 'no go signal'); exit(2); }
            usleep(500);
        }
        try {
            \$result = App\\Services\\Core\\CompanyService::runAs((int) \$args['company_id'], function () use (\$args) {
        {$scenario}
            });
            fwrite(STDOUT, json_encode(['ok' => true, 'result' => \$result]));
        } catch (Illuminate\\Validation\\ValidationException \$e) {
            fwrite(STDOUT, json_encode(['ok' => false, 'status' => 422, 'errors' => \$e->errors()]));
        } catch (Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface \$e) {
            fwrite(STDOUT, json_encode(['ok' => false, 'status' => \$e->getStatusCode(), 'message' => \$e->getMessage()]));
        } catch (Throwable \$e) {
            fwrite(STDERR, get_class(\$e).': '.\$e->getMessage());
            exit(1);
        }
        PHP;

        $scriptFile = tempnam(sys_get_temp_dir(), 'race-script-');
        $this->assertNotFalse($scriptFile);
        file_put_contents($scriptFile, $script);

        $pipes = [];
        $process = proc_open(
            PHP_BINARY.' '.escapeshellarg($scriptFile),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            getcwd(),
            TestCase::isolatedProcessEnvironment(['RACE_WORKER_PAYLOAD' => $payloadFile]),
        );

        if (! is_resource($process)) {
            @unlink($payloadFile);
            @unlink($scriptFile);
            $this->fail('Unable to start a race worker.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes, 'payload' => $payloadFile, 'script' => $scriptFile, 'ready' => $ready];
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>, payload: string, script: string, ready: string}  $worker
     * @return array{ok: bool, status?: int, result?: mixed, errors?: array<string, mixed>, message?: string}
     */
    private function finishRaceWorker(array $worker): array
    {
        $deadline = microtime(true) + 60;

        while (proc_get_status($worker['process'])['running']) {
            if (microtime(true) >= $deadline) {
                $this->fail('A race worker exceeded its 60-second deadline.');
            }

            usleep(5000);
        }

        $output = $this->raceOutput($worker);
        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded, 'A race worker failed: '.$output);

        return $decoded;
    }

    /** @param array{pipes: array<int, resource>} $worker */
    private function raceOutput(array $worker): string
    {
        $output = '';

        foreach ([1, 2] as $pipe) {
            if (is_resource($worker['pipes'][$pipe])) {
                $output .= (string) stream_get_contents($worker['pipes'][$pipe]);
            }
        }

        return $output;
    }

    /** @param array{process: resource, pipes: array<int, resource>, payload: string, script: string, ready: string} $worker */
    private function stopRaceWorker(array $worker): void
    {
        if (is_resource($worker['process'])) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }

            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            proc_close($worker['process']);
        }

        @unlink($worker['payload']);
        @unlink($worker['script']);
        @unlink($worker['ready']);
    }
}
