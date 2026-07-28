<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\CapabilityService;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function __construct(private readonly CapabilityService $capabilities) {}

    public function index(Request $request)
    {
        $this->capabilities->require($request->user(), 'audit_events.read');
        $context = $this->tenantContext($request);

        $validated = $request->validate([
            'action' => 'nullable|string|max:128',
            'targetType' => 'nullable|string|max:128',
            'targetId' => 'nullable|string|max:128',
            'correlationId' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = AuditEvent::query()
            ->where('activeTenantId', $context->activeTenantId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        foreach (['action', 'targetType', 'targetId', 'correlationId'] as $filter) {
            if (! empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $validated['to']);
        }

        return response()->json([
            'data' => $query->limit($validated['limit'] ?? 100)->get(),
        ]);
    }
}
