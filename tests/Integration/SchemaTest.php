<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use Tests\Support\DbTestCase;

/** NFR-01: MySQL 8, InnoDB, utf8mb4_unicode_ci, tabel wajib brief, PK/FK. */
final class SchemaTest extends DbTestCase
{
    private const REQUIRED = [
        'users', 'roles', 'permissions', 'customers', 'customer_contacts', 'projects', 'project_parts', 'npr', 'npr_parts',
        'npr_feedback', 'workflow_templates', 'workflow_template_versions', 'workflow_steps', 'processes', 'process_dependencies',
        'schedule_baselines', 'schedule_changes', 'documents', 'document_versions', 'approvals', 'approval_history',
        'trial_records', 'material_requests', 'validation_records', 'next_actions', 'hold_history', 'revision_history',
        'audit_logs', 'notifications', 'notification_deliveries', 'holidays', 'working_calendar', 'project_gates',
        'application_settings',
    ];

    public function testMysqlVersion8(): void
    {
        $this->assertMatchesRegularExpression('/^8\./', (string) Db::value('SELECT VERSION()'));
    }

    public function testAllRequiredTablesExist(): void
    {
        $tables = Db::column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        foreach (self::REQUIRED as $t) {
            $this->assertContains($t, $tables, "Tabel {$t} wajib ada");
        }
    }

    public function testEngineAndCollation(): void
    {
        $bad = Db::fetchAll("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND (ENGINE <> 'InnoDB' OR TABLE_COLLATION <> 'utf8mb4_unicode_ci')");
        $this->assertSame([], $bad);
        $badCols = Db::fetchAll("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_unicode_ci'");
        $this->assertSame([], $badCols);
    }

    public function testEveryTableHasPrimaryKey(): void
    {
        $noPk = Db::column("SELECT t.TABLE_NAME FROM information_schema.TABLES t
            LEFT JOIN information_schema.TABLE_CONSTRAINTS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
            WHERE t.TABLE_SCHEMA = DATABASE() AND c.CONSTRAINT_NAME IS NULL");
        $this->assertSame([], $noPk);
    }

    public function testForeignKeysPresent(): void
    {
        $fks = (int) Db::value("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $this->assertGreaterThan(100, $fks);
        // relasi inti Project → Part → Process → Dependency
        $pairs = Db::fetchAll("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL");
        $map = array_map(static fn ($r) => $r['TABLE_NAME'] . '>' . $r['REFERENCED_TABLE_NAME'], $pairs);
        foreach (['project_parts>projects', 'processes>project_parts', 'processes>projects', 'process_dependencies>processes', 'npr_parts>npr', 'projects>npr'] as $rel) {
            $this->assertContains($rel, $map);
        }
    }

    public function testSeedLoaded(): void
    {
        $this->assertSame(8, (int) Db::value('SELECT COUNT(*) FROM roles'));
        $this->assertSame(18, (int) Db::value("SELECT COUNT(*) FROM master_options WHERE category = 'document_type'"));
        $this->assertSame(5, (int) Db::value('SELECT COUNT(*) FROM working_calendar WHERE is_working = 1'));
        $this->assertSame(14, (int) Db::value("SELECT COUNT(*) FROM workflow_steps s JOIN workflow_templates t ON t.current_version_id = s.template_version_id WHERE t.code = 'new_mold'"));
        $this->assertSame(11, (int) Db::value("SELECT COUNT(*) FROM workflow_steps s JOIN workflow_templates t ON t.current_version_id = s.template_version_id WHERE t.code = 'subcont'"));
    }

    public function testNoAiAssistantArtifacts(): void
    {
        // UAT-25: tidak ada menu/halaman/endpoint AI Assistant
        $root = dirname(__DIR__, 2);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/public', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $f) {
            $this->assertDoesNotMatchRegularExpression('/assistant/i', $f->getFilename());
        }
        $tables = Db::column('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        $this->assertSame([], preg_grep('/assistant/i', $tables));
    }
}
