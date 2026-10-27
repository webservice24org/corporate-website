<?php

declare(strict_types=1);

namespace App\Services\ERP\Accounting;

use App\Models\Company;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/** Pure conversion helper. Caller obtains the company through authorized scope. */
final class AccountingBusinessDate
{
    public function today(Company $company): string
    {
        return $this->fromInstant($company, CarbonImmutable::now('UTC'));
    }

    public function fromInstant(Company $company, DateTimeInterface $instant): string
    {
        $zone = (string) $company->getAttribute('timezone');
        try {
            $timezone = new DateTimeZone($zone);
        } catch (\Exception) {
            throw ValidationException::withMessages(['company_id' => 'The company timezone must be reviewed before deriving an accounting date.']);
        }

        return CarbonImmutable::instance($instant)->setTimezone($timezone)->toDateString();
    }
}
