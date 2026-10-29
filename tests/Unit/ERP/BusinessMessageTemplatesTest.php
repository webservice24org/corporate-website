<?php

declare(strict_types=1);

namespace Tests\Unit\ERP;

use App\Services\Notifications\BusinessMessageTemplates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BusinessMessageTemplatesTest extends TestCase
{
    public function test_employee_audience_is_only_the_employee(): void
    {
        self::assertSame(['employee'], (new BusinessMessageTemplates)->audiences('employee.created'));
    }

    public function test_supported_tokens_are_replaced_without_evaluating_code(): void
    {
        self::assertSame('Hello Alex, REF-1', (new BusinessMessageTemplates)->render('Hello {name}, {reference}', ['name' => 'Alex', 'reference' => 'REF-1']));
    }

    public function test_unsupported_tokens_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BusinessMessageTemplates)->render('Your password is {password}', []);
    }
}
