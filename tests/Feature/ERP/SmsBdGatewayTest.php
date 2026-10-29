<?php

declare(strict_types=1);

namespace Tests\Feature\ERP;

use App\Services\Notifications\SmsBdGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class SmsBdGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sms-bd.enabled' => true, 'sms-bd.api_key' => 'test-key', 'sms-bd.sender_id' => null]);
        Http::preventStrayRequests();
    }

    public function test_success_means_submitted_not_delivered(): void
    {
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0, 'data' => ['request_id' => 123]], 200)]);
        $result = app(SmsBdGateway::class)->send('01700000000', 'Application received');
        self::assertSame(['status' => 'submitted', 'request_id' => '123'], $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.sms.net.bd/sendsms'
            && $request['to'] === '01700000000' && $request['msg'] === 'Application received');
    }

    public function test_disabled_gateway_does_not_send(): void
    {
        config(['sms-bd.enabled' => false]);
        Http::fake();
        try {
            app(SmsBdGateway::class)->send('01700000000', 'Hello');
            self::fail('Disabled SMS should fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('SMS delivery is disabled.', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_invalid_number_cannot_be_submitted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(SmsBdGateway::class)->send('invalid', 'Hello');
    }

    public function test_provider_error_is_not_treated_as_success(): void
    {
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 417, 'msg' => 'Insufficient balance'], 200)]);
        $this->expectException(RuntimeException::class);
        app(SmsBdGateway::class)->send('01700000000', 'Hello');
    }

    public function test_missing_request_id_is_not_treated_as_confirmed_submission(): void
    {
        Http::fake(['api.sms.net.bd/*' => Http::response(['error' => 0], 200)]);
        $this->expectException(RuntimeException::class);
        app(SmsBdGateway::class)->send('01700000000', 'Hello');
    }
}
