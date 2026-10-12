<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Notifications\LeadFollowUpReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendLeadFollowUpNotifications extends Command
{
    protected $signature =
        'crm:send-follow-up-notifications';

    protected $description =
        'Send notifications for CRM leads whose next follow-up time has arrived.';

    public function handle(): int
    {
        $sent = 0;

        Lead::query()
            ->where(
                'is_active',
                true
            )

            ->whereNotNull(
                'owner_user_id'
            )

            ->whereNotNull(
                'next_follow_up_at'
            )

            ->whereNull(
                'follow_up_notification_sent_at'
            )

            ->where(
                'next_follow_up_at',
                '<=',
                now()
            )

            /*
             * Do not remind terminal
             * Won/Lost leads.
             */
            ->whereHas(
                'status',
                function ($query) {
                    $query
                        ->where(
                            'is_won',
                            false
                        )
                        ->where(
                            'is_lost',
                            false
                        );
                }
            )

            ->with([
                'owner:id,name,email',
                'status:id,is_won,is_lost',
            ])

            ->chunkById(
                100,
                function ($leads) use (&$sent) {
                    foreach (
                        $leads as $lead
                    ) {
                        try {
                            DB::transaction(
                                function () use ($lead, &$sent) {
                                    /*
                                     * Lock and re-check so
                                     * duplicate scheduler
                                     * processes cannot send
                                     * twice.
                                     */
                                    $lockedLead =
                                        Lead::query()
                                            ->lockForUpdate()
                                            ->with([
                                                'owner:id,name,email',
                                            ])
                                            ->find(
                                                $lead->id
                                            );

                                    $owner = $lockedLead?->owner;
                                    $followUpAt = $lockedLead?->next_follow_up_at;

                                    if (
                                        !$lockedLead ||
                                        !$lockedLead->is_active ||
                                        !$owner instanceof User ||
                                        !is_string($followUpAt) ||
                                        $followUpAt === '' ||
                                        $lockedLead->follow_up_notification_sent_at ||
                                        CarbonImmutable::parse($followUpAt)->isFuture()
                                    ) {
                                        return;
                                    }

                                    $owner->notify(
                                        new LeadFollowUpReminder($lockedLead)
                                    );

                                    $lockedLead->forceFill([
                                        'follow_up_notification_sent_at' => now(),
                                    ])->saveQuietly();

                                    $sent++;
                                }
                            );
                        } catch (
                            Throwable $exception
                        ) {
                            report(
                                $exception
                            );

                            $this->error(
                                sprintf(
                                    'Failed to notify Lead #%d: %s',
                                    $lead->id,
                                    $exception->getMessage()
                                )
                            );
                        }
                    }
                }
            );

        $this->info(
            "{$sent} lead follow-up notification(s) sent."
        );

        return self::SUCCESS;
    }
}
