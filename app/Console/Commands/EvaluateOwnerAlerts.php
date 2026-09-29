<?php

namespace App\Console\Commands;

use App\Actions\Alerts\EvaluateAlerts;
use App\Actions\Alerts\NotifyAlerts;
use App\Actions\Alerts\SendMorningSummaries;
use App\Models\HeartbeatLog;
use App\Models\Organisation;
use App\Support\ActiveScope;
use Illuminate\Console\Command;

/**
 * Prompt 311 — every 15 minutes: work out what is over the line now, announce what is NEW by Telegram (one grouped
 * message per person), send the morning emails that are due, and beat its own heartbeat for *Salud del sistema*.
 * Re-running is safe: an alert that is already active is not announced again, and a morning email is once a day.
 */
class EvaluateOwnerAlerts extends Command
{
    protected $signature = 'alerts:evaluate';

    protected $description = 'Evaluate owner alerts (stock, expiry, tills, system), notify by Telegram and send due morning emails';

    public function handle(EvaluateAlerts $evaluate, NotifyAlerts $notify, SendMorningSummaries $summaries): int
    {
        $scope = app(ActiveScope::class);
        $previous = $scope->organisationId();

        foreach (Organisation::query()->get() as $organisation) {
            $scope->setOrganisation($organisation->id);
            $notify->handle($organisation, $evaluate->handle($organisation));
            $summaries->handle($organisation);
        }

        $scope->setOrganisation($previous);
        HeartbeatLog::beat('alerts');

        return self::SUCCESS;
    }
}
