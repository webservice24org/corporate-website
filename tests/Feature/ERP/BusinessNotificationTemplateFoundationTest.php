<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Models\BusinessNotificationTemplate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessNotificationTemplateFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_template_can_be_saved_disabled_and_edited(): void
    {
        $template = BusinessNotificationTemplate::query()->create([
            'event' => 'depot.submitted', 'audience' => 'applicant', 'channel' => 'email',
            'subject' => 'Application received', 'body' => 'Hello {name}',
        ]);

        self::assertFalse($template->fresh()->enabled);
        $template->update(['body' => 'Welcome {name}']);
        $this->assertDatabaseHas('business_notification_templates', ['id' => $template->getKey(), 'body' => 'Welcome {name}']);
    }

    public function test_same_event_audience_and_channel_cannot_be_duplicated(): void
    {
        $data = ['event' => 'dealer.approved', 'audience' => 'admin', 'channel' => 'sms', 'body' => 'Approved'];
        BusinessNotificationTemplate::query()->create($data);

        $this->expectException(QueryException::class);
        BusinessNotificationTemplate::query()->create($data);
    }
}
