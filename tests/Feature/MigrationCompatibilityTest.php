<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MigrationCompatibilityTest extends TestCase
{
    #[Test]
    public function migration_index_names_are_mysql_compatible(): void
    {
        $migrationFiles = glob(database_path('migrations/*.php')) ?: [];

        $this->assertNotEmpty($migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $source = (string) file_get_contents($migrationFile);
            $tableName = $this->tableNameFor($source, $migrationFile);

            preg_match_all(
                "/->(?:index|unique)\\([^;]*?,\\s*['\\\"]([^'\\\"]+)['\\\"]\\)/s",
                $source,
                $matches,
            );

            foreach ($matches[1] as $indexName) {
                $this->assertLessThanOrEqual(
                    64,
                    strlen($indexName),
                    sprintf('Index name %s in %s exceeds the MySQL identifier limit.', $indexName, basename($migrationFile)),
                );
            }

            preg_match_all(
                '/->(index|unique)\\(\\s*\\[([^\\]]+)\\]\\s*\\)\\s*;/s',
                $source,
                $implicitMatches,
                PREG_SET_ORDER,
            );

            foreach ($implicitMatches as $match) {
                $implicitIndexName = $this->implicitIndexName($tableName, $match[2], $match[1]);

                $this->assertLessThanOrEqual(
                    64,
                    strlen($implicitIndexName),
                    sprintf(
                        'Implicit index name %s in %s exceeds the MySQL identifier limit; provide an explicit short name.',
                        $implicitIndexName,
                        basename($migrationFile),
                    ),
                );
            }
        }
    }

    private function tableNameFor(string $source, string $migrationFile): string
    {
        if (preg_match("/Schema::create\\(\\s*['\\\"]([^'\\\"]+)['\\\"]/", $source, $match)) {
            return $match[1];
        }

        return basename($migrationFile, '.php');
    }

    private function implicitIndexName(string $tableName, string $columnsSource, string $type): string
    {
        $columns = array_map(
            fn (string $column): string => strtolower(preg_replace('/[^A-Za-z0-9_]/', '', trim($column))),
            explode(',', $columnsSource),
        );

        return sprintf('%s_%s_%s', $tableName, implode('_', $columns), $type === 'unique' ? 'unique' : 'index');
    }
}
