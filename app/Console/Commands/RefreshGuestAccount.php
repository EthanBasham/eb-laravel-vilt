<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\Finance\GuestFleet;
use App\Services\Wargaming\GuestGarage;

/**
 * Builds the shared guest account, or rebuilds it, from the owner's.
 *
 * Its sign-in is reset along with its data — name, password and all — so a
 * visitor who changes the password locks the others out until the next run at
 * most. Scheduled nightly. Each sub-project copies its own tables; see
 * GuestGarage and GuestFleet for what is copied and what is changed.
 */
class RefreshGuestAccount extends Command
{
    protected $signature = 'guest:refresh';

    protected $description = 'Rebuild the shared guest account from the owner\'s WOT Hub and Financial Fleet data';

    public function handle(GuestGarage $garage, GuestFleet $fleet): int
    {
        $password = config('guest.password');

        if (blank($password)) {
            $this->error('GUEST_PASSWORD is not set.');

            return self::FAILURE;
        }

        $source = User::query()->find(config('guest.source_user_id'));

        if (! $source) {
            $this->error('No user with id '.config('guest.source_user_id').' to copy from.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($source, $password, $garage, $fleet): void {
            $guest = User::query()->firstOrNew(['email' => config('guest.username')]);

            $guest->forceFill([
                'name' => config('guest.name'),
                'password' => $password,
                'email_verified_at' => $guest->email_verified_at ?? now(),
                'remember_token' => null,
            ])->save();

            $garage->copy($source, $guest);
            $fleet->copy($source, $guest, (float) config('guest.finance.scale'));
        });

        $this->info("Guest account rebuilt from {$source->name}'s.");

        return self::SUCCESS;
    }
}
