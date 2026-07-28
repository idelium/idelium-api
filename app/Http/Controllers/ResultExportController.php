<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateResultExportJob;
use App\Models\PerformedTestCycle;
use App\Models\ResultExport;
use App\Services\ResultExportService;
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
        $exports = app(ResultExportService::class);
        $export = ResultExport::forceCreate([
            'idCostumer' => Auth::user()->idCostumer,
            'performedTestCycleId' => $run->id,
            'format' => $format,
            'status' => 'queued',
            'filename' => $exports->filename($run, $format),
            'contentType' => $exports->contentType($format),
            'payload' => null,
            'expiresAt' => now()->addDay(),
        ]);

        GenerateResultExportJob::dispatch($export->id);

        return response()->json($this->descriptor($export), Response::HTTP_ACCEPTED);
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
            'ready' => $export->status === 'completed',
            'errorMessage' => $export->status === 'failed' ? $export->errorMessage : null,
        ];
    }

    private function assertOwned(ResultExport $export): void
    {
        abort_unless(
            (int) $export->idCostumer === (int) Auth::user()->idCostumer,
            Response::HTTP_NOT_FOUND
        );
    }
}
