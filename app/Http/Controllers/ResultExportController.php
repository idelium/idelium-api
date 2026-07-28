<?php

namespace App\Http\Controllers;

use App\Models\PerformedStep;
use App\Models\PerformedTest;
use App\Models\PerformedTestCycle;
use App\Models\ResultExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ResultExportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'performedTestCycleId' => ['required', 'integer'],
            'format' => ['required', Rule::in(['json', 'markdown'])],
        ]);

        $run = PerformedTestCycle::whereKey($validated['performedTestCycleId'])
            ->where('idCostumer', Auth::user()->idCostumer)
            ->firstOrFail();

        $format = $validated['format'];
        $payload = $this->payload($run, $format);
        $export = ResultExport::forceCreate([
            'idCostumer' => Auth::user()->idCostumer,
            'performedTestCycleId' => $run->id,
            'format' => $format,
            'status' => 'completed',
            'filename' => $this->filename($run, $format),
            'contentType' => $format === 'json' ? 'application/json' : 'text/markdown',
            'payload' => $payload,
            'expiresAt' => now()->addDay(),
        ]);

        return response()->json($this->descriptor($export), Response::HTTP_CREATED);
    }

    public function show(ResultExport $resultExport)
    {
        $this->assertOwned($resultExport);

        return response()->json($this->descriptor($resultExport));
    }

    public function download(ResultExport $resultExport)
    {
        $this->assertOwned($resultExport);
        abort_if($resultExport->status !== 'completed', Response::HTTP_CONFLICT);
        abort_if($resultExport->expiresAt->isPast(), Response::HTTP_GONE);

        return response($resultExport->payload, Response::HTTP_OK, [
            'Content-Type' => $resultExport->contentType,
            'Content-Disposition' => 'attachment; filename="'.$resultExport->filename.'"',
        ]);
    }

    private function descriptor(ResultExport $export): array
    {
        return [
            'id' => $export->id,
            'format' => $export->format,
            'status' => $export->status,
            'filename' => $export->filename,
            'contentType' => $export->contentType,
            'url' => '/api/admin/result-exports/'.$export->id.'/download',
            'expiresAt' => optional($export->expiresAt)->toIso8601String(),
            'authorized' => true,
        ];
    }

    private function payload(PerformedTestCycle $run, string $format): string
    {
        $tests = PerformedTest::where('testCycleDoneId', $run->id)
            ->where('idCostumer', Auth::user()->idCostumer)
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (PerformedTest $test) use ($run) {
                $steps = PerformedStep::where('testCycleDoneId', $run->id)
                    ->where('testDoneId', $test->id)
                    ->where('idCostumer', Auth::user()->idCostumer)
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

    private function filename(PerformedTestCycle $run, string $format): string
    {
        return 'idelium-run-'.$run->id.'.'.($format === 'json' ? 'json' : 'md');
    }

    private function assertOwned(ResultExport $export): void
    {
        abort_unless(
            (int) $export->idCostumer === (int) Auth::user()->idCostumer,
            Response::HTTP_NOT_FOUND
        );
    }
}
