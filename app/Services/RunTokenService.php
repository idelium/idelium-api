<?php

namespace App\Services;

use App\Models\ParallelRunSchedule;
use App\Models\RunToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RunTokenService
{
    public const TOKEN_PREFIX = 'idrt';

    /**
     * @return array{runToken: RunToken, token: string}
     */
    public function issue(ParallelRunSchedule $schedule, string $agentId): array
    {
        $tokenId = self::TOKEN_PREFIX.'_'.Str::random(24);
        $secret = Str::random(64);

        $runToken = RunToken::create([
            'idCostumer' => $schedule->idCostumer,
            'idProject' => $schedule->idProject,
            'parallelRunScheduleId' => $schedule->id,
            'agentId' => $agentId,
            'tokenId' => $tokenId,
            'tokenHash' => Hash::make($secret),
            'expiresAt' => now()->addSeconds((int) config('run_tokens.ttl_seconds', 300)),
        ]);

        return [
            'runToken' => $runToken,
            'token' => $tokenId.'.'.$secret,
        ];
    }

    public function consume(
        string $token,
        int $tenantId,
        int $projectId,
        int $parallelRunScheduleId,
        string $agentId
    ): RunToken {
        return DB::transaction(function () use (
            $token,
            $tenantId,
            $projectId,
            $parallelRunScheduleId,
            $agentId
        ) {
            [$tokenId, $secret] = $this->split($token);

            $runToken = RunToken::where('tokenId', $tokenId)->lockForUpdate()->first();
            if ($runToken === null
                || (int) $runToken->idCostumer !== $tenantId
                || (int) $runToken->idProject !== $projectId
                || (int) $runToken->parallelRunScheduleId !== $parallelRunScheduleId
                || $runToken->agentId !== $agentId
                || $runToken->isUsed()
                || $runToken->isRevoked()
                || $runToken->isExpired()
                || ! Hash::check($secret, $runToken->tokenHash)) {
                throw ValidationException::withMessages([
                    'runToken' => ['The run token is invalid, expired, used, or not bound to this agent.'],
                ]);
            }

            $runToken->forceFill(['usedAt' => now()])->save();

            return $runToken;
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function split(string $token): array
    {
        if (! str_contains($token, '.')) {
            throw ValidationException::withMessages([
                'runToken' => ['The run token format is invalid.'],
            ]);
        }

        [$tokenId, $secret] = explode('.', $token, 2);
        if (! str_starts_with($tokenId, self::TOKEN_PREFIX.'_') || $secret === '') {
            throw ValidationException::withMessages([
                'runToken' => ['The run token format is invalid.'],
            ]);
        }

        return [$tokenId, $secret];
    }
}
