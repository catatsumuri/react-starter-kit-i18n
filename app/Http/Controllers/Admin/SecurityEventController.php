<?php

namespace App\Http\Controllers\Admin;

use App\Actions\LogSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\SecurityEventResource;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SecurityEventController extends Controller
{
    /**
     * List the security log, newest first. The route is read-only on purpose.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'result' => ['nullable', Rule::in([LogSecurityEvent::RESULT_SUCCESS, LogSecurityEvent::RESULT_FAILURE])],
            'event' => ['nullable', 'string', 'max:100'],
        ]);

        $events = Activity::query()
            ->inLog(LogSecurityEvent::LOG_NAME)
            ->when($filters['result'] ?? null, fn ($query, string $result) => $query->where('properties->result', $result))
            ->when($filters['event'] ?? null, fn ($query, string $event) => $query->where('event', $event))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return SecurityEventResource::collection($events);
    }
}
