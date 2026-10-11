<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceProcessor;
use Illuminate\Console\Command;

class ProcessAttendancePunches extends Command
{
    protected $signature =
        'attendance:process
        {--limit=500 : Maximum pending punches to inspect}';

    protected $description =
        'Process resolved raw attendance punches into final attendance records';

    public function handle(
        AttendanceProcessor $processor
    ): int {
        $limit =
            max(
                1,
                (int) $this->option(
                    'limit'
                )
            );

        $this->info(
            'Processing attendance punches...'
        );

        $result =
            $processor->processPending(
                $limit
            );

        $this->table(
            [
                'Metric',
                'Value',
            ],
            [
                [
                    'Pending punches',
                    $result[
                        'pending_punches'
                    ],
                ],
                [
                    'Candidate days',
                    $result[
                        'candidate_days'
                    ],
                ],
                [
                    'Attendance records',
                    $result[
                        'processed_records'
                    ],
                ],
                [
                    'Failed days',
                    $result[
                        'failed_days'
                    ],
                ],
            ]
        );

        return self::SUCCESS;
    }
}