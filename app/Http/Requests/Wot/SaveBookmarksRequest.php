<?php

namespace App\Http\Requests\Wot;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The bookmarks bar as it should stand, posted whole.
 *
 * The editor sends the entire list including its order rather than one change
 * at a time, the way the crew editor sends a whole set: reordering and removing
 * are most of what gets done here, and both are awkward to express as a diff.
 * An empty array is a valid payload — it means an empty bar.
 */
class SaveBookmarksRequest extends FormRequest
{
    /**
     * Put a scheme on anything typed without one.
     *
     * Nobody types "https://" into a bookmarks field, and `url` rejects a bare
     * host outright, so without this the common case is a validation error on
     * a perfectly good address. Anything already carrying a scheme is left
     * alone, including the ones the `url` rule will go on to refuse.
     */
    protected function prepareForValidation(): void
    {
        $bookmarks = $this->input('bookmarks');

        if (! is_array($bookmarks)) {
            return;
        }

        $this->merge([
            'bookmarks' => array_map(function ($bookmark) {
                if (! is_array($bookmark) || ! is_string($bookmark['url'] ?? null)) {
                    return $bookmark;
                }

                $url = trim($bookmark['url']);

                return [...$bookmark, 'url' => $this->withScheme($url)];
            }, $bookmarks),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Capped because the bar is one line: past about this many the row
            // is all scrolling. It is headroom, not a design figure.
            'bookmarks' => ['present', 'array', 'max:50'],

            // Short, because the label is what the strip prints.
            'bookmarks.*.label' => ['required', 'string', 'max:40'],

            /*
             * http and https only. `url` on its own also passes javascript: and
             * data:, which is a stored-XSS hole the moment one is rendered into
             * an href — and this href is rendered for the person who typed it,
             * which is exactly who a self-XSS is aimed at.
             */
            'bookmarks.*.url' => ['required', 'string', 'max:2048', 'url:http,https'],

            'bookmarks.*.title' => ['nullable', 'string', 'max:200'],
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
            'bookmarks.*.url.url' => 'Each bookmark needs a web address, like https://tanks.gg.',
            'bookmarks.*.label.required' => 'Each bookmark needs a name.',
        ];
    }

    private function withScheme(string $url): string
    {
        if ($url === '' || str_contains($url, '://')) {
            return $url;
        }

        return 'https://'.$url;
    }
}
