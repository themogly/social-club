<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\User;
use App\Support\Email;
use Illuminate\Console\Command;

/**
 * Prompt 372 — read-only: the members (number and name) and staff (name) whose stored address mail cannot be sent to, with
 * the stored value in brackets so a stray space shows. Fix them in *Socios → editar* / *Usuarios*, then *Reenviar carné*.
 */
class ListInvalidEmailAddresses extends Command
{
    protected $signature = 'mail:invalid-addresses';

    protected $description = 'List members and staff whose stored email address cannot be sent to (read-only)';

    public function handle(): int
    {
        $found = 0;
        foreach (Member::query()->withoutGlobalScopes()->whereNotNull('email')->orderBy('member_no')->get(['member_no', 'first_name', 'last_name', 'email']) as $member) {
            if (! Email::isSendable($member->email)) {
                $this->line(sprintf('Member %s %s [%s]', $member->member_no, $member->fullName(), $member->email));
                $found++;
            }
        }
        foreach (User::query()->whereNotNull('email')->orderBy('name')->get(['name', 'email']) as $user) {
            if (! Email::isSendable($user->email)) {
                $this->line(sprintf('User %s [%s]', $user->name, $user->email));
                $found++;
            }
        }
        $this->info($found === 0 ? 'Every stored address can be sent to.' : "{$found} address(es) to fix.");

        return self::SUCCESS;
    }
}
