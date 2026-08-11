<?php

namespace Tests\Feature\Operations;

use Tests\TestCase;

class OperationsArtifactsTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function requiredDocs(): array
    {
        return [
            'docs/operations/DEPLOYMENT.md',
            'docs/operations/INCIDENT_ROLLBACK.md',
            'docs/operations/BACKUP_RESTORE.md',
            'docs/operations/RESTORE_DRILL_TEMPLATE.md',
            'docs/operations/SUPERVISION.md',
        ];
    }

    /**
     * @return list<string>
     */
    private function requiredScripts(): array
    {
        return [
            'scripts/operations/common.sh',
            'scripts/operations/backup.sh',
            'scripts/operations/restore.sh',
            'scripts/operations/restore-drill.sh',
            'scripts/operations/health-check.sh',
            'scripts/operations/deploy-local.sh',
            'scripts/operations/self-check.sh',
        ];
    }

    public function test_required_operations_docs_exist(): void
    {
        foreach ($this->requiredDocs() as $relative) {
            $this->assertFileExists(base_path($relative), "missing {$relative}");
        }
    }

    public function test_required_operations_scripts_exist(): void
    {
        foreach ($this->requiredScripts() as $relative) {
            $this->assertFileExists(base_path($relative), "missing {$relative}");
        }
    }

    public function test_backup_restore_doc_mentions_external_evidence_and_rpo_rto(): void
    {
        $body = file_get_contents(base_path('docs/operations/BACKUP_RESTORE.md'));
        $this->assertIsString($body);
        $this->assertStringContainsString('EXTERNAL_EVIDENCE_REQUIRED', $body);
        $this->assertMatchesRegularExpression('/RPO/i', $body);
        $this->assertMatchesRegularExpression('/RTO/i', $body);
        $this->assertMatchesRegularExpression('/defaults-extra-file/i', $body);
    }

    public function test_restore_script_refuses_blank_and_protected_patterns(): void
    {
        $restore = file_get_contents(base_path('scripts/operations/restore.sh'));
        $common = file_get_contents(base_path('scripts/operations/common.sh'));
        $this->assertIsString($restore);
        $this->assertIsString($common);

        $this->assertStringContainsString('refuse_blank_target', $common);
        $this->assertStringContainsString('PROTECTED_DB_NAMES_REGEX', $common);
        $this->assertStringContainsString('i-understand-production-restore', $restore);
        $this->assertStringContainsString('allow-protected', $restore);
        $this->assertStringContainsString('inbils_restore_drill_', $restore);
        $this->assertStringContainsString('EXTERNAL_EVIDENCE_REQUIRED', $restore);
    }

    public function test_backup_script_requires_database_and_output_dir(): void
    {
        $backup = file_get_contents(base_path('scripts/operations/backup.sh'));
        $this->assertIsString($backup);
        $this->assertStringContainsString('--database', $backup);
        $this->assertStringContainsString('--output-dir', $backup);
        $this->assertStringContainsString('refuse_blank_target', $backup);
        $this->assertStringContainsString('EXTERNAL_EVIDENCE_REQUIRED', $backup);
    }

    public function test_deploy_local_forbids_migrate_fresh(): void
    {
        $deploy = file_get_contents(base_path('scripts/operations/deploy-local.sh'));
        $this->assertIsString($deploy);
        $this->assertStringContainsString('migrate:fresh', $deploy);
        $this->assertMatchesRegularExpression('/forbidden/i', $deploy);
        $this->assertStringNotContainsString('artisan migrate:fresh', $deploy);
    }

    public function test_deployment_doc_documents_health_contract(): void
    {
        $body = file_get_contents(base_path('docs/operations/DEPLOYMENT.md'));
        $this->assertIsString($body);
        $this->assertStringContainsString('/up', $body);
        $this->assertStringContainsString('/ready', $body);
        $this->assertStringContainsString('migrate:fresh', $body);
    }
}
