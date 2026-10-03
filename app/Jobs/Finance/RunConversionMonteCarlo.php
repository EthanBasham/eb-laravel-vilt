<?php

namespace App\Jobs\Finance;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Models\Finance\MonteCarloRun;
use App\Models\User;
use App\Services\Finance\ConversionMonteCarlo;
use Throwable;

/**
 * A Monte Carlo run too big for a page load, worked out off the request.
 *
 * Unique per user: asking again while one is waiting does not queue a second.
 * It reads the settings and strategies when it starts, not when it was queued.
 */
class RunConversionMonteCarlo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A big run on a slow machine; nothing like this in practice. */
    public int $timeout = 900;

    public int $tries = 1;

    /** How long the uniqueness lock outlives a worker that died holding it. */
    public int $uniqueFor = 900;

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(ConversionMonteCarlo $monteCarlo): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $monteCarlo->runInBackground($user);
    }

    public function failed(?Throwable $exception): void
    {
        MonteCarloRun::query()->where('user_id', $this->userId)->update([
            'status' => 'failed',
            'error' => str($exception?->getMessage() ?? 'The run stopped.')->limit(250)->toString(),
        ]);
    }
}
