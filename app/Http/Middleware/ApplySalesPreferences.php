<?php

namespace App\Http\Middleware;

use App\Services\PreferencesService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ApplySalesPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        $preferences = app(PreferencesService::class)->all();
        View::share('preferences', $preferences);
        if (! $request->isMethod('GET')) {
            foreach (['performed_at', 'planned_at', 'received_at', 'sent_at', 'due_at'] as $field) {
                if ($request->filled($field) && is_string($request->input($field))) {
                    try {
                        $request->merge([$field => Carbon::parse($request->input($field), $preferences['timezone'])->utc()->toIso8601String()]);
                    } catch (\Throwable) {
                    }
                }
            }
        }

        return $next($request);
    }
}
