<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

class MarkArticlesSeenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Capped: the client batches what scrolled past, and a page only
            // holds 24 cards. A larger payload means something is wrong, and
            // "mark all as seen" is the supported way to clear a backlog.
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ];
    }
}
