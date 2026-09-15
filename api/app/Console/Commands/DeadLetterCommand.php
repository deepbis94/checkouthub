<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class DeadLetterCommand extends Command
{
    protected $signature = 'checkouthub:dlq {--limit=50}';

    protected $description = 'List dead-letter / failed jobs from Redis (BullMQ) and Laravel failed_jobs.';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $queues = ['webhooks.outbound', 'subscriptions.renew', 'gateways.probe', 'checkouts.expire'];

        foreach ($queues as $queue) {
            $key = "bull:{$queue}:failed";
            $count = (int) Redis::llen($key);
            $this->line("bull:{$queue}:failed = {$count}");

            if ($count > 0) {
                $items = Redis::lrange($key, 0, $limit - 1);
                foreach ($items as $item) {
                    $this->line('  '.$item);
                }
            }
        }

        return self::SUCCESS;
    }
}
