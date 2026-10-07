<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use App\Models\Finance\OwnedModel;

/**
 * Base for the Financial Fleet form requests: the rules more than one of
 * them needs.
 */
abstract class FinanceRequest extends FormRequest
{
    /**
     * An id that has to be one of the signed-in user's own rows of a model.
     * Every id taken from input goes through this, so that nobody can point
     * a record of theirs at somebody else's; further `where`s can be chained
     * onto what it returns.
     *
     * @param  class-string<OwnedModel>  $model
     */
    protected function owned(string $model): Exists
    {
        return Rule::exists((new $model)->getTable(), 'id')->where('user_id', $this->user()->id);
    }

    /**
     * Checks the keys of `overrides`, which are the years being pinned and
     * which a per-field rule cannot see: each has to be a year from this one
     * on. Says whether they passed, so a caller can stop there.
     *
     * @param  string  $noun  What a pinned year is given: "an amount", "a value".
     */
    protected function validatePinnedYears(Validator $validator, string $noun): bool
    {
        $overrides = $this->input('overrides');

        if (! is_array($overrides)) {
            return false;
        }

        foreach (array_keys($overrides) as $year) {
            if (! ctype_digit((string) $year) || (int) $year < now()->year || (int) $year > now()->year + 150) {
                $validator->errors()->add('overrides', "Only a year from this one on can be given {$noun} of its own.");

                return false;
            }
        }

        return true;
    }
}
