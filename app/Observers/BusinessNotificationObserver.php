<?php

declare(strict_types=1);

namespace App\Observers;

use App\Services\Notifications\BusinessNotificationRecorder;
use Illuminate\Database\Eloquent\Model;

final class BusinessNotificationObserver
{
    public function created(Model $model): void
    {
        app(BusinessNotificationRecorder::class)->record($model, false);
    }

    public function updated(Model $model): void
    {
        if ($model->wasChanged('status') && $model->getAttribute('status') === 'approved') {
            app(BusinessNotificationRecorder::class)->record($model, true);
        }
    }
}
