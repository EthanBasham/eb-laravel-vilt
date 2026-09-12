<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Collection;
use App\Models\WotAccount;
use App\Models\WotCrewBook;
use App\Models\WotCrewRecruit;

/**
 * Recruits & Books: the two stockpiles behind a crew.
 *
 * Both are rendered as a full grid — every kind of recruit, every book against
 * every nation — while the database holds only the rows that have been edited.
 * So this class's whole job is to lay the stored quantities over the lists in
 * config and hand back something the page can draw without knowing which cells
 * happen to exist.
 *
 * That is also what keeps the two in step: the config list is the order on the
 * page, the keys the form requests validate against, and the set of cells a
 * total is taken over.
 */
class CrewInventory
{
    /**
     * @return array<string, mixed>
     */
    public function for(WotAccount $account): array
    {
        return [
            'recruits' => $this->recruits($account),
            'books' => $this->books($account),
        ];
    }

    /**
     * The barracks, one row per kind, in config order.
     *
     * @return array<string, mixed>
     */
    private function recruits(WotAccount $account): array
    {
        $held = WotCrewRecruit::where('wot_account_id', $account->id)
            ->pluck('quantity', 'recruit_key');

        $rows = collect((array) config('wargaming.crew_recruits'))
            ->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'quantity' => (int) ($held[$key] ?? 0),
            ])
            ->values();

        return ['rows' => $rows->all(), 'total' => (int) $rows->sum('quantity')];
    }

    /**
     * The books, as nations down and types across.
     *
     * 'universal' is a row rather than a column: a universal book is the same
     * booklet, guide or manual as a national one, held without a nation
     * attached, so it belongs under the nations rather than beside the types.
     *
     * The two special items follow it with a single count each, which lands in
     * the same total column the book rows end with — there is no type for them
     * to sit under.
     *
     * @return array<string, mixed>
     */
    private function books(WotAccount $account): array
    {
        $held = WotCrewBook::where('wot_account_id', $account->id)
            ->get()
            ->keyBy(fn (WotCrewBook $book): string => "{$book->book_type}:{$book->nation}");

        $types = collect((array) config('wargaming.crew_books'))
            ->map(fn (array $book, string $key): array => ['key' => $key, ...$book])
            ->values();

        $rows = $this->bookRows($types, $held);

        return [
            'types' => $types->all(),
            'rows' => $rows->all(),
            'specials' => $this->specials($held)->all(),
            'totals' => $this->bookTotals($types, $rows),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $types
     * @param  Collection<string, WotCrewBook>  $held
     * @return Collection<int, array<string, mixed>>
     */
    private function bookRows(Collection $types, Collection $held): Collection
    {
        // The nations in tech-tree order, then the stack that spends anywhere.
        $nations = collect((array) config('wargaming.nations'))
            ->map(fn (string $label, string $nation): array => ['nation' => $nation, 'label' => $label])
            ->values()
            ->push(['nation' => WotCrewBook::UNIVERSAL, 'label' => 'Universal']);

        return $nations->map(function (array $nation) use ($types, $held): array {
            $quantities = $types->mapWithKeys(fn (array $type): array => [
                $type['key'] => (int) ($held->get("{$type['key']}:{$nation['nation']}")?->quantity ?? 0),
            ]);

            return [
                ...$nation,
                'quantities' => $quantities->all(),
                'total' => (int) $quantities->sum(),
            ];
        });
    }

    /**
     * @param  Collection<string, WotCrewBook>  $held
     * @return Collection<int, array<string, mixed>>
     */
    private function specials(Collection $held): Collection
    {
        return collect((array) config('wargaming.crew_book_specials'))
            ->map(fn (string $name, string $key): array => [
                'key' => $key,
                'name' => $name,
                // Stored under 'universal' like the books they sit beneath:
                // neither is tied to a nation, and the column they land in is
                // the books' total column.
                'quantity' => (int) ($held->get("{$key}:".WotCrewBook::UNIVERSAL)?->quantity ?? 0),
            ])
            ->values();
    }

    /**
     * Column sums, plus the grand total.
     *
     * Books only. The specials are counted in neither, because a Personal
     * Training Manual is not a booklet, a guide or a manual and adding it to
     * the bottom of those columns would make the total mean nothing.
     *
     * @param  Collection<int, array<string, mixed>>  $types
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function bookTotals(Collection $types, Collection $rows): array
    {
        $totals = $types->mapWithKeys(fn (array $type): array => [
            $type['key'] => (int) $rows->sum(fn (array $row): int => $row['quantities'][$type['key']]),
        ]);

        return [...$totals->all(), 'total' => (int) $totals->sum()];
    }
}
