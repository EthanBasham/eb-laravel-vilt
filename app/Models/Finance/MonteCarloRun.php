<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Database\Factories\Finance\MonteCarloRunFactory;

/**
 * A user's Monte Carlo settings for the Roth conversion strategizer, and the
 * results of the last background run. See ConversionMonteCarlo.
 */
#[Fillable(['user_id', 'runs', 'return_volatility', 'inflation_volatility', 'seed', 'status', 'inputs_hash', 'results', 'ran_at', 'error'])]
class MonteCarloRun extends OwnedModel
{
    /** @use HasFactory<MonteCarloRunFactory> */
    use HasFactory;

    protected $table = 'fin_monte_carlo_runs';

    /**
     * What the settings read as before the user has changed any.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'runs' => 100,
        'return_volatility' => 12,
        'inflation_volatility' => 1,
        'seed' => 1,
        'status' => 'idle',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runs' => 'integer',
            'return_volatility' => 'float',
            'inflation_volatility' => 'float',
            'seed' => 'integer',
            'results' => 'array',
            'ran_at' => 'datetime',
        ];
    }

    /**
     * This user's settings, or an unsaved row carrying the defaults. Not
     * firstOrCreate, for the reason Profile::for() gives.
     */
    public static function for(User $user): self
    {
        return static::query()->onlyOwnedBy($user)->first() ?? new static(['user_id' => $user->id]);
    }
}
