<?php

namespace App\Console\Commands;

use App\Services\Attendance\AttendancePunchResolver;
use Illuminate\Console\Command;

class ResolveAttendancePunches extends Command
{
    protected $signature =
        'attendance:resolve-unmapped';

    protected $description =
        'Resolve unmapped raw attendance punches using active device employee mappings';

    public function handle(
        AttendancePunchResolver $resolver
    ): int {
        $this->info(
            'Resolving unmapped attendance punches...'
        );

        $resolved =
            $resolver->resolveAll();

        if ($resolved === 0) {
            $this->info(
                'No unmapped punches could be resolved.'
            );

            return self::SUCCESS;
        }

        $this->info(
            "{$resolved} punch" .
            ($resolved === 1 ? '' : 'es') .
            ' resolved successfully.'
        );

        return self::SUCCESS;
    }
}