<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Validator;
use App\Models\Finance\ConversionStrategy;
use App\Models\Finance\Scenario;

/**
 * The whole Roth report, replaced: each strategy named on each projection
 * named, and nothing else.
 */
class ReplaceReportRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'strategies' => ['required', 'array', 'min:1'],
            'strategies.*' => ['integer', 'distinct', $this->owned(ConversionStrategy::class)],
            // Null is the income and expenses as entered.
            'projections' => ['required', 'array', 'min:1'],
            'projections.*' => ['nullable', 'integer', 'distinct', $this->owned(Scenario::class)],
        ];
    }

    /**
     * The report holds so many columns, and these are one for each strategy
     * on each projection.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $most = (int) config('finance.conversion_comparison.max');
                $strategies = count((array) $this->input('strategies'));
                $projections = count((array) $this->input('projections'));

                if ($strategies * $projections > $most) {
                    $validator->errors()->add('strategies', "No more than {$most} can be in the report at once: {$strategies} strategies on {$projections} projections is ".$strategies * $projections.'.');
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'strategies.required' => 'Pick at least one strategy for the report.',
            'projections.required' => 'Pick at least one projection to run them on.',
        ];
    }
}
