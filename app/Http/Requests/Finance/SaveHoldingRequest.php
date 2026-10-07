<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Models\Finance\Armada;
use App\Models\Finance\Holding;

/**
 * An asset or a liability, created or edited.
 *
 * `side` is not accepted: it follows from the type, and holdingAttributes()
 * sets it from config so the two can never disagree. `armada_id` is cleared
 * on an account inside another, which follows the outer account's.
 */
class SaveHoldingRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_keys(config('finance.holding_types')))],
            // The two facts about a retirement account. Required for one, and
            // discarded for anything else — see holdingAttributes().
            'plan_type' => ['nullable', 'required_if:type,retirement', 'string', Rule::in(array_keys(config('finance.retirement_plans')))],
            'tax_type' => ['nullable', 'required_if:type,retirement', 'string', Rule::in(array_keys(config('finance.retirement_tax_types')))],
            'name' => ['required', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:80'],
            'balance' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'annual_rate' => ['required', 'numeric', 'between:-100,100'],
            'monthly_contribution' => ['required', 'numeric', 'min:0', 'max:999999999'],
            /*
             * Only one of the user's own assets can stand behind a debt.
             * Without the user_id constraint this would accept any id in the
             * table, and the holding page would then print someone else's
             * property name beside the loan.
             */
            'secured_by_id' => [
                'nullable',
                'integer',
                $this->owned(Holding::class)->where('side', 'asset'),
                Rule::notIn([$this->route('holding')?->id]),
            ],
            /*
             * The account this one sits inside. It has to be the user's own,
             * and has to stand at the top level itself — whereNull is what
             * keeps the nesting one deep. Whether the parent's type may hold
             * this type is checked in after(), once both are known to exist.
             */
            'parent_id' => [
                'nullable',
                'integer',
                $this->owned(Holding::class)->whereNull('parent_id'),
                Rule::notIn([$this->route('holding')?->id]),
            ],
            'armada_id' => ['nullable', 'integer', $this->owned(Armada::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The rules that need two fields, or the row being edited, to judge.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['type', 'parent_id'])) {
                    return;
                }

                $type = $this->input('type');
                $editing = $this->route('holding');

                if ($this->filled('parent_id')) {
                    $parent = Holding::query()->find($this->input('parent_id'));
                    $holds = (array) config("finance.holding_types.{$parent->type}.holds", []);

                    if (! in_array($type, $holds, true)) {
                        $validator->errors()->add('parent_id', "A {$parent->type_label} cannot hold this type of account.");
                    }

                    // Moving a compound account inside another would put its
                    // own children two levels down.
                    if ($editing?->children()->exists()) {
                        $validator->errors()->add('parent_id', 'An account that holds other accounts cannot be placed inside one.');
                    }
                }

                // Changing a compound account's type out from under the
                // accounts inside it would leave them in something that
                // cannot hold them.
                if ($editing && config("finance.holding_types.{$type}.holds") === null && $editing->children()->exists()) {
                    $validator->errors()->add('type', 'Move or remove the accounts inside this one before changing its type.');
                }
            },
        ];
    }

    /**
     * The validated input as the columns to write: `side` filled in from the
     * type, and the retirement facts cleared on anything that is not a
     * retirement account, so a holding changed from one to another type does
     * not keep describing itself as a Roth IRA.
     *
     * @return array<string, mixed>
     */
    public function holdingAttributes(): array
    {
        $validated = $this->validated();
        $isRetirement = $validated['type'] === 'retirement';

        return [
            ...$validated,
            'side' => config("finance.holding_types.{$validated['type']}.side"),
            'plan_type' => $isRetirement ? $validated['plan_type'] : null,
            'tax_type' => $isRetirement ? $validated['tax_type'] : null,
            'parent_id' => $validated['parent_id'] ?? null,
            // An account inside another sails with the outer one.
            'armada_id' => ($validated['parent_id'] ?? null) ? null : ($validated['armada_id'] ?? null),
        ];
    }
}
