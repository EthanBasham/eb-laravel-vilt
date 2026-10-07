<?php

namespace App\Http\Requests\Finance;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use App\Models\Finance\Holding;

/**
 * An automated transfer, created or edited.
 */
class SaveTransferRequest extends FinanceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $own = fn () => $this->owned(Holding::class);

        return [
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', 'string', Rule::in(array_keys(config('finance.transfer_kinds')))],
            // Money only ever leaves an asset.
            'from_holding_id' => ['required', 'integer', $own()->where('side', 'asset')],
            // It may arrive at a debt, which it pays down.
            'to_holding_id' => ['required', 'integer', 'different:from_holding_id', $own()],
            // A month's amount for a fixed transfer; an optional ceiling
            // on the month for the other two.
            'amount' => ['nullable', 'required_if:kind,fixed', 'numeric', 'min:0', 'max:999999999'],
            'keep_balance' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'sort_order' => ['required', 'integer', 'between:0,999'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['from_holding_id', 'to_holding_id', 'kind'])) {
                    return;
                }

                // Money moves between accounts that carry a balance of their
                // own, not ones that are only the sum of others.
                foreach (['from_holding_id', 'to_holding_id'] as $end) {
                    if (Holding::query()->where('parent_id', $this->input($end))->exists()) {
                        $validator->errors()->add($end, 'Pick one of the accounts inside it.');
                    }
                }

                // Keeping a debt "topped up" to a balance means nothing.
                if ($this->input('kind') === 'top_up' && Holding::query()->whereKey($this->input('to_holding_id'))->where('side', 'liability')->exists()) {
                    $validator->errors()->add('to_holding_id', 'A top-up refills an asset. To pay a debt down, sweep or send a fixed amount to it.');
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
            'amount.required_if' => 'Say how much to move each month.',
            'to_holding_id.different' => 'A transfer needs two different ends.',
        ];
    }
}
