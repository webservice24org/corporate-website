<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Development CLI recovery preflight. Never drops, truncates, updates or copies an existing table. */
final class AccountingFoundationRecovery
{
    private const PREFIX = ['fiscal_years', 'accounting_periods', 'account_groups', 'accounts', 'accounting_mapping_versions', 'accounting_account_mappings', 'journal_entries', 'journal_entry_lines', 'accounting_source_effects', 'accounting_document_sequences'];

    private const LATER = ['accounting_audit_events', 'accounting_cutovers', 'accounting_cutover_sources', 'accounting_company_settings'];

    private const MANIFEST_HASH = '0b03700b41bde2d80519b33840577c2e2686d004b3cc80e57a976455388e4165';

    /** @return list<string> */
    public function inspect(): array
    {
        if (PHP_SAPI !== 'cli' || DB::connection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('This recovery is limited to the reported MySQL CLI migration failure.');
        }
        $path = base_path('tools/accounting/foundation-recovery-manifest.json');
        if (! is_file($path) || hash_file('sha256', $path) !== self::MANIFEST_HASH) {
            throw new \RuntimeException('The recovery manifest is missing or changed. Restore it from the fix ZIP.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read recovery metadata.');
        }
        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! isset($manifest['tables']) || ! is_array($manifest['tables']) || array_column($manifest['tables'], 'table') !== self::PREFIX) {
            throw new \RuntimeException('Unexpected recovery scope.');
        }
        $migrationConfig = config('database.migrations');
        $migrationTable = is_array($migrationConfig) ? ($migrationConfig['table'] ?? 'migrations') : (is_string($migrationConfig) ? $migrationConfig : 'migrations');
        if (! is_string($migrationTable) || ! Schema::hasTable($migrationTable)) {
            throw new \RuntimeException('Migration repository is unavailable; stop for review.');
        }
        foreach (DB::table($migrationTable)->pluck('migration') as $name) {
            if (is_string($name) && str_ends_with($name, '_create_accounting_domain_foundation')) {
                throw new \RuntimeException('An accounting foundation migration is already recorded. Do not use partial-migration recovery.');
            }
        }
        foreach (self::LATER as $table) {
            if (Schema::hasTable($table)) {
                throw new \RuntimeException('Unexpected later accounting table exists: '.$table.'. Stop for review.');
            }
        }
        $verified = [];
        $gap = false;
        foreach ($manifest['tables'] as $definition) {
            $table = $definition['table'];
            if (! Schema::hasTable($table)) {
                $gap = true;

                continue;
            }
            if ($gap) {
                throw new \RuntimeException('Accounting tables are not a completed migration prefix. Stop for review.');
            }
            if (DB::table($table)->exists()) {
                throw new \RuntimeException('Recovery refused: '.$table.' contains rows. No data is changed.');
            }
            $columns = Schema::getColumnListing($table);
            sort($columns);
            if ($columns !== $definition['columns']) {
                throw new \RuntimeException('Recovery refused: unexpected columns in '.$table);
            }
            $columnRows = DB::select('SELECT COLUMN_NAME AS column_name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS max_length, NUMERIC_PRECISION AS numeric_precision, NUMERIC_SCALE AS numeric_scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            foreach ($columnRows as $column) {
                $expected = $definition['column_types'][$column->column_name];
                $type = strtolower((string) $column->data_type);
                // MariaDB represents Laravel JSON columns as LONGTEXT with a JSON validation constraint.
                $typeMatches = $type === $expected['type'] || ($expected['type'] === 'json' && $type === 'longtext');
                if (! $typeMatches || (($column->is_nullable === 'YES') !== $expected['nullable']) || (isset($expected['unsigned']) && ! str_contains(strtolower((string) $column->column_type), 'unsigned')) || (isset($expected['length']) && (int) $column->max_length !== $expected['length']) || (isset($expected['precision']) && ((int) $column->numeric_precision !== $expected['precision'] || (int) $column->numeric_scale !== $expected['scale']))) {
                    throw new \RuntimeException('Recovery refused: incompatible type in '.$table.'.'.$column->column_name);
                }
            }
            // Identifiers come only from the fixed allowlist and hash-verified manifest above.
            $indexes = DB::select('SHOW INDEX FROM `'.$table.'`');
            $owner = [];
            foreach ($indexes as $index) {
                if ($index->Key_name === $definition['owner_index'] && (int) $index->Non_unique === 0) {
                    $owner[(int) $index->Seq_in_index] = $index->Column_name;
                }
            }
            ksort($owner);
            if (array_values($owner) !== ['id', 'company_id']) {
                throw new \RuntimeException('Recovery refused: ownership index missing in '.$table);
            }
            foreach ($definition['required_indexes'] as $requiredIndex) {
                $indexColumns = [];
                foreach ($indexes as $index) {
                    if ($index->Key_name === $requiredIndex['name'] && ((int) $index->Non_unique === 0) === $requiredIndex['unique']) {
                        $indexColumns[(int) $index->Seq_in_index] = $index->Column_name;
                    }
                }
                ksort($indexColumns);
                if (array_values($indexColumns) !== $requiredIndex['columns']) {
                    throw new \RuntimeException('Recovery refused: index '.$requiredIndex['name'].' is incomplete in '.$table);
                }
            }

            $rows = DB::select('SELECT CONSTRAINT_NAME AS constraint_name, COLUMN_NAME AS local_column, REFERENCED_TABLE_SCHEMA AS target_schema, REFERENCED_TABLE_NAME AS target_table, REFERENCED_COLUMN_NAME AS target_column FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION', [$table]);
            $foreign = [];
            foreach ($rows as $row) {
                if ($row->target_schema !== DB::connection()->getDatabaseName()) {
                    throw new \RuntimeException('Cross-database foreign key found; stop for review.');
                }
                $key = $row->constraint_name;
                $foreign[$key]['columns'][] = $row->local_column;
                $foreign[$key]['foreign_table'] = $row->target_table;
                $foreign[$key]['foreign_columns'][] = $row->target_column;
            }
            $actual = [];
            $expectedKeys = [];
            foreach ($foreign as $fk) {
                $actual[] = implode(',', $fk['columns']).'|'.$fk['foreign_table'].'|'.implode(',', $fk['foreign_columns']);
            }
            foreach ($definition['foreign_keys'] as $fk) {
                $expectedKeys[] = implode(',', $fk['columns']).'|'.$fk['foreign_table'].'|'.implode(',', $fk['foreign_columns']);
            }
            sort($actual);
            sort($expectedKeys);
            if ($actual !== $expectedKeys) {
                throw new \RuntimeException('Recovery refused: incomplete or unexpected foreign keys in '.$table);
            }
            $verified[] = $table;
        }

        return $verified;
    }
}
