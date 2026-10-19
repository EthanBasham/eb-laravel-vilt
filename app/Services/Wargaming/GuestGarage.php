<?php

namespace App\Services\Wargaming;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Models\WotAccount;

/**
 * Copies one user's World of Tanks side over another's — the owner's onto the
 * guest account.
 *
 * The guest is linked to the same Wargaming account, but without the access
 * token: the dashboard reads the account's public statistics live, as it does
 * for the owner, and nothing behind the token (credits, Free XP) is ever
 * fetched for it. Everything typed in by hand — grinding plans, crews, guides,
 * bookmarks — and the snapshot history are copied as they are.
 */
class GuestGarage
{
    /**
     * Copied wholesale: rows that hang off the account and have nothing
     * hanging off them.
     */
    private const ACCOUNT_TABLES = [
        'wot_snapshots',
        'wot_vehicle_snapshots',
        'wot_tank_purchases',
        'wot_tank_modules',
        'wot_blueprints',
        'wot_crew_recruits',
        'wot_crew_books',
        'wot_battle_pass_crew',
        'wot_grind_settings',
    ];

    /**
     * The user's own choices about shared rows, and the bookmark strip.
     */
    private const USER_TABLES = [
        'wot_bookmarks',
        'wot_article_pins',
        'wot_article_views',
        'wot_event_ignores',
    ];

    /**
     * Replaces everything the guest has in the sub-project with a copy of the
     * source's. A source with no linked account leaves the guest unlinked.
     */
    public function copy(User $source, User $guest): void
    {
        DB::transaction(function () use ($source, $guest): void {
            // The account takes everything hanging off it, by cascade.
            $guest->wotAccount()->delete();

            foreach (self::USER_TABLES as $table) {
                DB::table($table)->where('user_id', $guest->id)->delete();
                $this->copyRows($table, 'user_id', $source->id, $guest->id);
            }

            // Without this the strip would seed the defaults on top of the
            // copied bookmarks on the guest's first visit.
            $guest->forceFill(['wot_bookmarks_seeded_at' => $source->wot_bookmarks_seeded_at])->save();

            $account = $source->wotAccount;

            if (! $account) {
                return;
            }

            $guestAccount = WotAccount::query()->create([
                'user_id' => $guest->id,
                'account_id' => $account->account_id,
                'nickname' => $account->nickname,
                'access_token' => null,
                'access_token_expires_at' => null,
                'last_synced_at' => $account->last_synced_at,
            ]);

            foreach (self::ACCOUNT_TABLES as $table) {
                $this->copyRows($table, 'wot_account_id', $account->id, $guestAccount->id);
            }

            $this->copyWithChildren('wot_tank_crews', 'wot_crew_members', 'wot_tank_crew_id', $account->id, $guestAccount->id);
            $this->copyWithChildren('wot_crew_guides', 'wot_crew_guide_roles', 'wot_crew_guide_id', $account->id, $guestAccount->id);
        });
    }

    /**
     * Copies a table's rows from one owner to another in a single INSERT ...
     * SELECT, every column but the id carried across.
     */
    private function copyRows(string $table, string $ownerColumn, int $fromId, int $toId): void
    {
        $columns = array_values(array_diff(Schema::getColumnListing($table), ['id']));

        $select = DB::table($table)
            ->where($ownerColumn, $fromId)
            ->orderBy('id')
            ->select(array_map(fn (string $column) => $column === $ownerColumn ? DB::raw((string) $toId) : $column, $columns));

        DB::table($table)->insertUsing($columns, $select);
    }

    /**
     * Copies an account's rows one at a time, each followed by the rows that
     * hang off it.
     */
    private function copyWithChildren(string $table, string $childTable, string $parentColumn, int $fromAccountId, int $toAccountId): void
    {
        $rows = DB::table($table)->where('wot_account_id', $fromAccountId)->orderBy('id')->get();

        foreach ($rows as $row) {
            $attributes = (array) $row;
            unset($attributes['id']);

            $newId = DB::table($table)->insertGetId([...$attributes, 'wot_account_id' => $toAccountId]);

            $this->copyRows($childTable, $parentColumn, $row->id, $newId);
        }
    }
}
