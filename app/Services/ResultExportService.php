<?php

namespace App\Services;

use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;

class ResultExportService
{
    public function payload(PerformedTestCycle $run, int $tenantId, string $format): string
    {
        $tests = PerformedTest::where('testCycleDoneId', $run->id)
            ->where('idCostumer', $tenantId)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (PerformedTest $test) use ($run, $tenantId) {
                $steps = PerformedStep::where('testCycleDoneId', $run->id)
                    ->where('testDoneId', $test->id)
                    ->where('idCostumer', $tenantId)
                    ->orderBy('id', 'asc')
                    ->get(['id', 'name', 'status', 'type', 'created_at', 'updated_at']);

                return [
                    'id' => $test->id,
                    'name' => $test->name,
                    'status' => $test->status,
                    'steps' => $steps,
                ];
            });

        $document = [
            'schemaVersion' => 'result-export.v1',
            'performedTestCycleId' => $run->id,
            'testCycleId' => $run->testCycleId,
            'status' => $run->status,
            'date' => $run->date,
            'tests' => $tests,
        ];

        if ($format === 'json') {
            return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $this->markdown($document);
    }

    public function filename(PerformedTestCycle $run, string $format): string
    {
        return 'idelium-run-'.$run->id.'.'.($format === 'json' ? 'json' : 'md');
    }

    public function contentType(string $format): string
    {
        return $format === 'json' ? 'application/json' : 'text/markdown';
    }

    private function markdown(array $document): string
    {
        $lines = [
            '# Idelium execution report',
            '',
            '- Schema: '.$document['schemaVersion'],
            '- Performed test cycle: '.$document['performedTestCycleId'],
            '- Source test cycle: '.$document['testCycleId'],
            '- Status: '.$document['status'],
            '- Date: '.$document['date'],
            '',
            '## Tests',
            '',
        ];

        foreach ($document['tests'] as $test) {
            $lines[] = '### '.$test['name'];
            $lines[] = '';
            $lines[] = '- Test ID: '.$test['id'];
            $lines[] = '- Status: '.$test['status'];
            $lines[] = '- Steps: '.count($test['steps']);
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }
}
