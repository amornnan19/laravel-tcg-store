<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('shop:make-staff {email : The address of the staff account} {--name= : Display name for a new account (default: the part of the email before @)} {--reset-password : Give an existing account a new random password and print it}')]
#[Description('Create a staff account, or promote an existing user to staff')]
class MakeStaff extends Command
{
    private const PASSWORD_LENGTH = 24;

    /**
     * A new address gets a verified staff account with a random password, printed once. An existing user becomes staff;
     * when that is a change, the remember token is rotated and their database sessions are deleted, because a session
     * made before `is_staff` was set reaches `/admin` without the second-factor challenge. Operator output, so English.
     */
    public function handle(): int
    {
        $email = User::normalizeEmail((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("'{$email}' is not a valid email address.");

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->createStaff($email);

            return self::SUCCESS;
        }

        $this->promote($user);

        return self::SUCCESS;
    }

    private function createStaff(string $email): void
    {
        $password = Str::random(self::PASSWORD_LENGTH);

        $user = new User(['name' => $this->option('name') ?: Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_staff' => true, 'email_verified_at' => now()])->save();

        $this->info("Created staff account {$email}.");
        $this->line("Password (shown once): {$password}");
    }

    private function promote(User $user): void
    {
        $wasStaff = $user->is_staff === true;

        $user->forceFill(['is_staff' => true]);

        if (! $wasStaff) {
            $user->remember_token = Str::random(60);
        }

        $password = null;

        if ($this->option('reset-password')) {
            $password = Str::random(self::PASSWORD_LENGTH);
            $user->password = $password;
        }

        $user->save();

        if ($wasStaff) {
            $this->info("{$user->email} is already staff.");
        } else {
            $ended = DB::table((string) config('session.table'))->where('user_id', $user->getKey())->delete();

            $this->info("Promoted {$user->email} to staff.");
            $this->line("Rotated the remember token and ended {$ended} session(s); they must sign in again.");
        }

        if ($password !== null) {
            $this->line("New password (shown once): {$password}");
        } elseif (! $wasStaff) {
            $this->line('Their password is unchanged. Staff cannot use "forgot password"; pass --reset-password to set a new one.');
        }
    }
}
