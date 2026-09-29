<?php

namespace App\Console\Commands;

use App\Support\Telegram;
use Illuminate\Console\Command;

/** Prompt 311 — register the bot's webhook (this app's `/telegram/webhook`) with Telegram, carrying the secret header. */
class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Register the owner-alerts bot webhook with Telegram';

    public function handle(): int
    {
        if (! Telegram::configured() || blank(config('services.telegram.webhook_secret'))) {
            $this->error('Set TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME and TELEGRAM_WEBHOOK_SECRET first (then php artisan config:cache).');

            return self::FAILURE;
        }

        $response = Telegram::setWebhook(route('telegram.webhook'));
        if (! $response->successful() || ! $response->json('ok')) {
            $this->error('Telegram refused the webhook: '.(string) $response->json('description'));

            return self::FAILURE;
        }

        $this->info('Webhook registered: '.route('telegram.webhook'));

        return self::SUCCESS;
    }
}
