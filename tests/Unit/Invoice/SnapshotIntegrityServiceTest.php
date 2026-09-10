<?php

namespace Tests\Unit\Invoice;

use App\Services\Invoice\SnapshotIntegrityService;
use Tests\TestCase;

class SnapshotIntegrityServiceTest extends TestCase
{
    private SnapshotIntegrityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SnapshotIntegrityService();
    }

    public function test_compute_hash_is_deterministic(): void
    {
        $data = ['a' => 1, 'b' => 2, 'c' => 3];

        $hash1 = $this->service->computeHash($data);
        $hash2 = $this->service->computeHash($data);

        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_changes_when_data_changes(): void
    {
        $original = ['a' => 1, 'b' => 2];
        $modified = ['a' => 1, 'b' => 3];

        $hash1 = $this->service->computeHash($original);
        $hash2 = $this->service->computeHash($modified);

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_hash_is_independent_of_key_order(): void
    {
        $data1 = ['a' => 1, 'b' => 2, 'c' => 3];
        $data2 = ['c' => 3, 'a' => 1, 'b' => 2];

        $hash1 = $this->service->computeHash($data1);
        $hash2 = $this->service->computeHash($data2);

        $this->assertSame($hash1, $hash2);
    }

    public function test_verify_returns_true_for_valid_data(): void
    {
        $data = ['key' => 'value', 'nested' => ['inner' => 42]];
        $hash = $this->service->computeHash($data);

        $this->assertTrue($this->service->verify($data, $hash));
    }

    public function test_verify_returns_false_for_tampered_data(): void
    {
        $data = ['key' => 'value'];
        $hash = $this->service->computeHash($data);

        $tampered = ['key' => 'different_value'];

        $this->assertFalse($this->service->verify($tampered, $hash));
    }

    public function test_verify_uses_hash_equals(): void
    {
        $data = ['test' => 'data'];
        $hash = $this->service->computeHash($data);

        $this->assertTrue($this->service->verify($data, $hash));
    }

    public function test_hash_is_sha256_length(): void
    {
        $data = ['any' => 'data'];
        $hash = $this->service->computeHash($data);

        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function test_nested_array_produces_deterministic_hash(): void
    {
        $data = [
            'customer' => ['name' => 'John', 'id' => 1],
            'items' => [['id' => 1, 'price' => 10.5], ['id' => 2, 'price' => 20.0]],
        ];

        $hash1 = $this->service->computeHash($data);
        $hash2 = $this->service->computeHash($data);

        $this->assertSame($hash1, $hash2);
    }

    public function test_unicode_characters_are_handled(): void
    {
        $data = ['name' => 'José García', 'city' => 'القاهرة'];

        $hash = $this->service->computeHash($data);
        $this->assertIsString($hash);
        $this->assertEquals(64, strlen($hash));
    }

    public function test_empty_array_produces_valid_hash(): void
    {
        $hash = $this->service->computeHash([]);

        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
