<?php

namespace App\Console\Commands;

use App\Jobs\PurgeArtifactDescriptorJob;
use App\Services\ArtifactLifecycleService;
use Illuminate\Console\Command;

class PurgeExpiredArtifactsCommand extends Command
{
    protected $signature = 'artifacts:purge-expired {--limit=100 : Maximum number of descriptors to enqueue}';

    protected $description = 'Enqueue idempotent hard-delete jobs for artifact descriptors whose retention period expired.';

    public function handle(ArtifactLifecycleService $artifactLifecycle): int
    {
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $candidates = $artifactLifecycle->hardDeleteCandidates($limit);

        foreach ($candidates as $artifact) {
            PurgeArtifactDescriptorJob::dispatch($artifact->id);
        }

        $this->info('Queued '.$candidates->count().' artifact purge jobs.');

        return self::SUCCESS;
    }
}
