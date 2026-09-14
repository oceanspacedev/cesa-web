<?php

namespace Cesa\Rekrutmen\Console\Commands;

use Cesa\Rekrutmen\Services\ScheduledNotificationService;
use Illuminate\Console\Command;

class ProcessScheduledNotificationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rekrutmen:process-scheduled-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process and send due scheduled candidate notifications (Email & WhatsApp)';

    /**
     * Execute the console command.
     */
    public function handle(ScheduledNotificationService $service): int
    {
        $this->info('Checking for due scheduled candidate notifications...');

        $processedCount = $service->processDueNotifications();

        $this->info("Processed {$processedCount} scheduled notification batch(es).");

        return self::SUCCESS;
    }
}
