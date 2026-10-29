<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use InvalidArgumentException;

final class BusinessMessageTemplates
{
    public const EVENTS = ['depot.submitted', 'depot.approved', 'dealer.submitted', 'dealer.approved', 'employee.created'];

    public const TOKENS = ['name', 'business_name', 'reference', 'status', 'company_name', 'employee_number'];

    /** @return list<string> */
    public function audiences(string $event): array
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new InvalidArgumentException('Unsupported notification event.');
        }

        return $event === 'employee.created' ? ['employee'] : ['applicant', 'admin'];
    }

    /** @param array<string, string> $values */
    public function render(string $text, array $values): string
    {
        $this->validate($text);
        $replace = [];
        foreach (self::TOKENS as $token) {
            $replace['{'.$token.'}'] = $values[$token] ?? '';
        }

        return strtr($text, $replace);
    }

    public function validate(string $text): void
    {
        preg_match_all('/\{([^{}]*)\}/u', $text, $matches);
        foreach ($matches[1] as $token) {
            if (! in_array($token, self::TOKENS, true)) {
                throw new InvalidArgumentException('Unsupported placeholder: {'.$token.'}.');
            }
        }
    }

    /** @return array{subject: string, body: string} */
    public function defaults(string $event, string $audience): array
    {
        $this->audiences($event);
        $label = ucfirst(str_replace('.', ' application ', $event));
        if ($event === 'employee.created') {
            return ['subject' => 'Welcome to {company_name}', 'body' => 'Hello {name}, your employee record {employee_number} has been created at {company_name}. Please contact the administration team for further information.'];
        }
        if ($audience === 'admin') {
            return ['subject' => $label.' — {reference}', 'body' => '{company_name}: {business_name} ({reference}) is {status}. Please review the application in the ERP.'];
        }
        $body = str_ends_with($event, '.approved')
            ? 'Hello {name}, your application {reference} for {business_name} has been approved by {company_name}. Thank you.'
            : 'Hello {name}, {company_name} has received your application {reference} for {business_name}. It is awaiting review. Thank you.';

        return ['subject' => $label.' — {reference}', 'body' => $body];
    }
}
