<?php

namespace App\Jobs;

use App\Models\PerformedTestCycle;
use App\Models\ResultExport;
use App\Services\ResultExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class GenerateResultExportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $resultExportId
    ) {}

    public function handle(ResultExportService $exports): void
    {
        $export = ResultExport::query()->find($this->resultExportId);
        if (! $export instanceof ResultExport || $export->status === 'completed') {
            return;
        }

        if ($export->expiresAt->isPast()) {
            $export->forceFill([
                'status' => 'expired',
                'errorMessage' => 'The export expired before it could be generated.',
            ])->save();

            return;
        }

        $run = PerformedTestCycle::whereKey($export->performedTestCycleId)
            ->where('idCostumer', $export->idCostumer)
            ->first();

        if (! $run instanceof PerformedTestCycle) {
            $export->forceFill([
                'status' => 'failed',
                'errorMessage' => 'The performed test cycle is not available for this tenant.',
            ])->save();

            return;
        }

        try {
            $export->forceFill([
                'status' => 'completed',
                'payload' => $exports->payload($run, (int) $export->idCostumer, $export->format),
                'errorMessage' => null,
            ])->save();
        } catch (Throwable $exception) {
            $export->forceFill([
                'status' => 'failed',
                'errorMessage' => Str::limit($exception->getMessage(), 1000, ''),
            ])->save();
        }
    }

    public function resultExportId(): int
    {
        return $this->resultExportId;
    }
}
