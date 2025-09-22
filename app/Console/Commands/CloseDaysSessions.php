<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DaysTopupService;

class CloseDaysSessions extends Command
{
    protected $signature = 'days:close-sessions';
    protected $description = 'Close expired 5h windows, compute expectations, and update balances';

    public function handle(DaysTopupService $svc): int
    {
        $n = $svc->closeExpiredSessions();
        $this->info("Closed $n sessions.");
        return self::SUCCESS;
    }
}
