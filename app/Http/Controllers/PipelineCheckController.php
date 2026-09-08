<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use App\Http\Requests\PipelineCheckRequest;

/**
 * Backs the "pipeline check" on the home page: a deliberately trivial endpoint
 * whose only job is to prove the front-end stack is wired end to end — Blade
 * markup, the jQuery bundle, the CSRF header set by app.js, form request
 * validation, and a JSON response rendered without a reload.
 *
 * It exists because the scaffold otherwise has no server round-trip to exercise.
 * Delete it once a real sub-project does.
 */
class PipelineCheckController extends Controller
{
    public function store(PipelineCheckRequest $request): JsonResponse|RedirectResponse
    {
        $result = [
            'received' => $request->string('message')->toString(),
            'reversed' => Str::reverse($request->string('message')->toString()),
            'handled_by' => sprintf('PHP %s · Laravel %s', PHP_VERSION, app()->version()),
            'at' => now()->toTimeString(),
        ];

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        // No-JS path: same work, delivered through the session instead.
        return back()->with('pipeline_check', $result);
    }
}
