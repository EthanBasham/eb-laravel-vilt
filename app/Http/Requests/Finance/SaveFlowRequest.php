<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Models\Finance\Flow;
use App\Models\Finance\Holding;

/**
 * An income stream or an expense, created or edited.
 */
class SaveFlowRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $direction = $this->input('direction');

        return [
            'direction' => ['required', 'string', Rule::in(['income', 'expense'])],
            // The category list depends on the direction, so a salary cannot
            // be filed as an expense by posting the two fields mismatched.
            'category' => ['required', 'string', Rule::in(array_keys(config("finance.flow_categories.{$direction}") ?? []))],
            'name' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'frequency' => ['required', 'string', Rule::in(array_keys(config('finance.frequencies')))],
            'hours_per_week' => ['nullable', 'required_if:frequency,hourly', 'numeric', 'between:0,168'],
            'annual_growth_rate' => ['required', 'numeric', 'between:-50,50'],
            // Null is an income that is not taxed. An expense has no treatment.
            'taxation' => ['nullable', 'prohibited_if:direction,expense', 'string', Rule::in(array_keys(config('finance.flow_taxations')))],
            // The share of the income that is taxed at all. Only read when
            // it has a treatment; an expense carries the default and ignores it.
            'taxed_portion' => ['required', 'numeric', 'between:0,100'],
            'is_essential' => ['required', 'boolean'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'holding_id' => ['nullable', 'integer', Rule::exists('fin_holdings', 'id')->where('user_id', $this->user()->id)],
            'armada_id' => ['nullable', 'integer', Rule::exists('fin_armadas', 'id')->where('user_id', $this->user()->id)],
            // The asset an income is paid into, or an expense out of.
            'account_id' => ['nullable', 'integer', Rule::exists('fin_holdings', 'id')->where('user_id', $this->user()->id)->where('side', 'asset')],
            /*
             * The flow this one is an item of. It has to be the user's own
             * and stand at the top level itself — whereNull is what keeps
             * the nesting one deep. Whether it can hold items at all is
             * checked in after(), once both are known to exist.
             */
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('fin_flows', 'id')->where('user_id', $this->user()->id)->whereNull('parent_id'),
                Rule::notIn([$this->route('flow')?->id]),
            ],
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
                if ($validator->errors()->hasAny(['direction', 'category', 'parent_id', 'account_id'])) {
                    return;
                }

                $isItemized = fn (string $direction, string $category): bool => (bool) config("finance.flow_categories.{$direction}.{$category}.itemized", false);
                $editing = $this->route('flow');

                if ($this->filled('parent_id')) {
                    $parent = Flow::query()->find($this->input('parent_id'));

                    if (! $parent->is_itemized || $parent->direction !== $this->input('direction')) {
                        $validator->errors()->add('parent_id', "{$parent->name} cannot hold items.");
                    }

                    if ($isItemized($this->input('direction'), $this->input('category'))) {
                        $validator->errors()->add('category', 'An item cannot itself hold items. Pick an ordinary category.');
                    }

                    // Moving a flow that holds items inside another would
                    // put its own items two levels down.
                    if ($editing?->children()->exists()) {
                        $validator->errors()->add('parent_id', 'A flow that holds items cannot be placed inside another.');
                    }
                }

                // Recategorising a flow out from under its items would leave
                // them inside something that cannot hold them.
                if ($editing && ! $isItemized($this->input('direction'), $this->input('category')) && $editing->children()->exists()) {
                    $validator->errors()->add('category', 'Move or remove the items inside this one before changing its category.');
                }

                // Money lands in an account that carries a balance of its
                // own, not in one that is only the sum of others.
                if ($this->filled('account_id') && Holding::query()->where('parent_id', $this->input('account_id'))->exists()) {
                    $validator->errors()->add('account_id', 'Pick one of the accounts inside it.');
                }
            },
        ];
    }

    /**
     * The validated input as the columns to write. An item inside another
     * flow belongs to no holding and no armada of its own — it follows the
     * flow it sits in — and a flow hung off a holding follows the holding's
     * armada, so neither keeps one that would be ignored.
     *
     * @return array<string, mixed>
     */
    public function flowAttributes(): array
    {
        $validated = $this->validated();
        $parentId = $validated['parent_id'] ?? null;
        $holdingId = $parentId ? null : ($validated['holding_id'] ?? null);

        return [
            ...$validated,
            'parent_id' => $parentId,
            'holding_id' => $holdingId,
            'armada_id' => $parentId || $holdingId ? null : ($validated['armada_id'] ?? null),
            'account_id' => $validated['account_id'] ?? null,
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
            'hours_per_week.required_if' => 'An hourly rate needs the hours a week it is worked.',
        ];
    }
}
