<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\ProcessBusinessNotifications;
use App\Models\DealerApplication;
use App\Models\DepotApplication;
use App\Models\Employee;
use App\Observers\BusinessNotificationObserver;
use Illuminate\Support\ServiceProvider;

final class BusinessNotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        DepotApplication::observe(BusinessNotificationObserver::class);
        DealerApplication::observe(BusinessNotificationObserver::class);
        Employee::observe(BusinessNotificationObserver::class);
        if ($this->app->runningInConsole()) {
            $this->commands([ProcessBusinessNotifications::class]);
        }
    }
}
