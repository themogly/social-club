<?php

namespace App\Console\Commands;

use App\Support\Telegram;
use Illuminate\Console\Command;

/**
 * Prompt 363 — a REAL check of the bot token: Telegram's `getMe`. «Configured» only means the settings are filled in; this
 * says whether Telegram accepts them, with the bot's username or the exact HTTP status and description. Never prints the
 * token. `telegram:set-webhook` runs it first.
 */
class CheckTelegram extends Command
{
    protected $signature = 'telegram:check';

    protected $description = 'Check the owner-alerts bot token with Telegram (getMe)';

    public function handle(): int
    {
        if (! Telegram::configured()) {
            $this->error(__('Falta TELEGRAM_BOT_TOKEN o TELEGRAM_BOT_USERNAME (después, php artisan config:cache).'));

            return self::FAILURE;
        }

        $response = Telegram::getMe();
        if (! $response->successful() || ! $response->json('ok')) {
            $this->error(__('Telegram rechaza el token: HTTP :status :description. Copia el token actual en @BotFather → /mybots → API Token, ponlo en TELEGRAM_BOT_TOKEN y ejecuta php artisan config:cache.', [
                'status' => $response->status(), 'description' => (string) $response->json('description'),
            ]));

            return self::FAILURE;
        }

        Telegram::clearTokenRejected();
        $this->info(__('Token correcto: el bot es @:username.', ['username' => (string) $response->json('result.username')]));

        return self::SUCCESS;
    }
}
