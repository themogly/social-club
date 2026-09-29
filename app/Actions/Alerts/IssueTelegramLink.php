<?php

namespace App\Actions\Alerts;

use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Support\Telegram;
use Illuminate\Support\Str;

/**
 * Prompt 311 — *Conectar Telegram*: a one-time `t.me/<bot>?start=<code>` link. The code is random, stored only as its
 * SHA-256, valid for {@see TelegramLinkCode::MINUTES} minutes and used once ({@see LinkTelegramChat}). Issuing a new one
 * retires any unused code the person still had.
 */
class IssueTelegramLink
{
    public function handle(User $user): string
    {
        $code = Str::random(32);

        TelegramLinkCode::query()->where('user_id', $user->id)->whereNull('used_at')->delete();
        TelegramLinkCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes(TelegramLinkCode::MINUTES),
        ]);

        return Telegram::startLink($code);
    }
}
