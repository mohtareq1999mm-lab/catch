<?php

namespace Tests\Feature\Phase0;

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Jobs\ImportBrandsJob;
use Marvel\Jobs\ImportCategoriesJob;
use Marvel\Jobs\ImportProductsJob;
use Tests\TestCase;

/**
 * P10 regression: intermediate import attempts must stay retryable.
 * Pre-fix behavior wrote status=failed on EVERY throwable, so the
 * terminal-state guard turned tries 2..N into silent no-ops.
 */
class ImportRetrySemanticsTest extends TestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->string('password')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('imports')) {
            Schema::create('imports', function ($t) {
                $t->id();
                $t->string('type')->default('product');
                $t->string('file_path');
                $t->string('file_name');
                $t->string('images_source')->default('none');
                $t->string('zip_file_path')->nullable();
                $t->string('status')->default('pending');
                $t->integer('total_rows')->default(0);
                $t->integer('processed_rows')->default(0);
                $t->integer('success_rows')->default(0);
                $t->integer('failed_rows')->default(0);
                $t->json('errors')->nullable();
                $t->foreignId('created_by')->nullable();
                $t->timestamps();
            });
        }

        $this->userId = (int) DB::table('users')->insertGetId([
            'name' => 'importer',
            'email' => 'importer@test.local',
            'password' => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::fake('public');
    }

    private function createProcessingImport(string $type): int
    {
        return (int) DB::table('imports')->insertGetId([
            'type' => $type,
            'file_path' => "imports/{$type}-fixture.xlsx",
            'file_name' => "{$type}-fixture.xlsx",
            'status' => 'processing',
            'created_by' => $this->userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeQueueJob(int $attempt): QueueJobContract
    {
        $queueJob = \Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('getConnectionName')->zeroOrMoreTimes()->andReturn('database');
        $queueJob->shouldReceive('getQueue')->zeroOrMoreTimes()->andReturn('meem-high');

        return $queueJob;
    }

    /** @test */
    public function product_import_intermediate_attempt_stays_retryable()
    {
        Excel::shouldReceive('import')->andThrow(new \RuntimeException('boom'));

        $importId = $this->createProcessingImport('product');

        $job = new ImportProductsJob($importId);
        $job->setJob($this->fakeQueueJob(attempt: 1));

        try {
            $job->handle();
            $this->fail('Expected the underlying exception to propagate for retry accounting');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $row = DB::table('imports')->where('id', $importId)->first();
        $this->assertSame('processing', $row->status, 'Intermediate attempt must NOT flip to failed');
        $errors = json_decode($row->errors ?? '[]', true) ?: [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Attempt 1', $errors[0]['error_message']);
    }

    /** @test */
    public function product_import_terminal_attempt_marks_failed()
    {
        Excel::shouldReceive('import')->andThrow(new \RuntimeException('boom-final'));

        $importId = $this->createProcessingImport('product');

        $job = new ImportProductsJob($importId);
        $job->setJob($this->fakeQueueJob(attempt: 3)); // == tries

        try {
            $job->handle();
            $this->fail('Expected terminal failure');
        } catch (\RuntimeException) {
        }

        $row = DB::table('imports')->where('id', $importId)->first();
        $this->assertSame('failed', $row->status, 'Terminal attempt must flip to failed');
        $this->assertStringContainsString('boom-final', $row->errors);
    }

    /** @test */
    public function product_import_failed_status_makes_next_attempt_an_early_noop()
    {
        Excel::spy();

        $importId = $this->createProcessingImport('product');
        DB::table('imports')->where('id', $importId)->update(['status' => 'failed']);

        $job = new ImportProductsJob($importId);
        $job->setJob($this->fakeQueueJob(attempt: 2));

        $job->handle();

        Excel::shouldNotHaveReceived('import');
    }

    /** @test */
    public function category_import_intermediate_attempt_stays_retryable()
    {
        Excel::shouldReceive('import')->andThrow(new \RuntimeException('cat-boom'));

        $importId = $this->createProcessingImport('category');

        $job = new ImportCategoriesJob($importId);
        $job->setJob($this->fakeQueueJob(attempt: 1));

        try {
            $job->handle();
            $this->fail('Expected propagation');
        } catch (\RuntimeException) {
        }

        $this->assertSame(
            'processing',
            DB::table('imports')->where('id', $importId)->value('status')
        );
    }

    /** @test */
    public function brand_import_intermediate_attempt_stays_retryable()
    {
        Excel::shouldReceive('import')->andThrow(new \RuntimeException('brand-boom'));

        $importId = $this->createProcessingImport('brand');

        $job = new ImportBrandsJob($importId);
        $job->setJob($this->fakeQueueJob(attempt: 2));

        try {
            $job->handle();
            $this->fail('Expected propagation');
        } catch (\RuntimeException) {
        }

        $this->assertSame(
            'processing',
            DB::table('imports')->where('id', $importId)->value('status')
        );
    }
}
