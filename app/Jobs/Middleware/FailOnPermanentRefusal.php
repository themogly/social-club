<?php

namespace App\Jobs\Middleware;

use App\Mail\ClubMail;
use Throwable;

/**
 * Prompt 372 — fail a queued club mail at ONCE when Resend's refusal will not change on retry ({@see ClubMail::isPermanent()}),
 * instead of spending its four tries. Laravel's own `FailOnException` keeps a Closure, which cannot be serialised onto the
 * queue with the mail; this holds nothing, so it travels with the job.
 */
class FailOnPermanentRefusal
{
    /**
     * @param  object  $job  the queued job (it has `fail()`)
     */
    public function handle(object $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (Throwable $e) {
            if (ClubMail::isPermanent($e) && method_exists($job, 'fail')) {
                $job->fail($e);
            }

            throw $e;
        }
    }
}
