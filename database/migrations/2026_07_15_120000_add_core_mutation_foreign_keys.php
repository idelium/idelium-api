<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $relationships = [
        ['environments', 'idProject', 'projects'],
        ['plugins', 'idProject', 'projects'],
        ['steps', 'idProject', 'projects'],
        ['tests', 'idProject', 'projects'],
        ['test_cycles', 'idProject', 'projects'],
        ['performed_test_cycles', 'testCycleId', 'test_cycles'],
        ['performed_tests', 'testCycleDoneId', 'performed_test_cycles'],
        ['performed_tests', 'testId', 'tests'],
        ['performed_steps', 'testCycleDoneId', 'performed_test_cycles'],
        ['performed_steps', 'testDoneId', 'performed_tests'],
        ['performed_steps', 'stepId', 'steps'],
    ];

    public function up(): void
    {
        foreach ($this->relationships as [$childTable, $childColumn, $parentTable]) {
            $orphanCount = DB::table($childTable)
                ->leftJoin(
                    $parentTable,
                    $childTable.'.'.$childColumn,
                    '=',
                    $parentTable.'.id'
                )
                ->whereNull($parentTable.'.id')
                ->count();

            if ($orphanCount > 0) {
                throw new RuntimeException(sprintf(
                    'Foreign-key preflight failed: %d orphaned %s.%s value(s).',
                    $orphanCount,
                    $childTable,
                    $childColumn
                ));
            }
        }

        foreach ($this->relationships as [$childTable, $childColumn, $parentTable]) {
            Schema::table($childTable, function (Blueprint $table) use (
                $childColumn,
                $parentTable
            ) {
                $table->unsignedBigInteger($childColumn)->change();
                $table->foreign($childColumn)
                    ->references('id')
                    ->on($parentTable)
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->relationships) as [$childTable, $childColumn]) {
            Schema::table($childTable, function (Blueprint $table) use ($childColumn) {
                $table->dropForeign([$childColumn]);
                $table->integer($childColumn)->change();
            });
        }
    }
};
