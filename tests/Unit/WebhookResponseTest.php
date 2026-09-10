<?php

namespace Tests\Unit;

use App\Support\WebhookResponse;
use Tests\TestCase;

class WebhookResponseTest extends TestCase
{
    /** @test */
    public function it_creates_success_response()
    {
        $response = new WebhookResponse(
            success: true,
            statusCode: 200,
            body: '{"status":"ok"}',
            headers: ['content-type' => ['application/json']],
            duration: 150.5,
            attempts: 1,
        );

        $this->assertTrue($response->success);
        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals('{"status":"ok"}', $response->body);
        $this->assertEquals(['content-type' => ['application/json']], $response->headers);
        $this->assertEquals(150.5, $response->duration);
        $this->assertEquals(1, $response->attempts);
        $this->assertNull($response->error);
    }

    /** @test */
    public function it_creates_failure_response()
    {
        $response = new WebhookResponse(
            success: false,
            statusCode: 0,
            error: 'Connection timeout',
        );

        $this->assertFalse($response->success);
        $this->assertEquals(0, $response->statusCode);
        $this->assertEquals('Connection timeout', $response->error);
        $this->assertNull($response->body);
        $this->assertEquals([], $response->headers);
        $this->assertEquals(0.0, $response->duration);
        $this->assertEquals(1, $response->attempts);
    }

    /** @test */
    public function it_accepts_custom_attempts()
    {
        $response = new WebhookResponse(
            success: true,
            statusCode: 200,
            attempts: 3,
        );

        $this->assertEquals(3, $response->attempts);
    }
}
