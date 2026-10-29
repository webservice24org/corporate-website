<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\BusinessNotificationProcessor;
use Illuminate\Console\Command;

final class ProcessBusinessNotifications extends Command
{
    protected $signature = 'business-notifications:process {--limit=25 : Maximum pending records to process}';

    protected $description = 'Process pending business notifications without retrying uncertain external sends';

    public function handle(BusinessNotificationProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 200) {
            $this->error('Use a limit between 1 and 200.');

            return self::FAILURE;
        }
        $this->info('Processed '.$processor->process($limit).' record(s).');

        return self::SUCCESS;
    }
}
