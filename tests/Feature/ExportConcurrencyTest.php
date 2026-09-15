<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\User;
use Marvel\Jobs\ExportCategoriesJob;
use Tests\TestCase;

class ExportConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
    }

    public function test_two_category_exports_have_unique_filenames_and_both_exist(): void
    {
        Category::create(['name'=>['en'=>'CatA'],'slug'=>'cata-'.uniqid()]);
        Category::create(['name'=>['en'=>'CatB'],'slug'=>'catb-'.uniqid()]);

        $user = User::create(['name'=>'U','email'=>'u-'.uniqid().'@test.com','password'=>bcrypt('p'),'is_active'=>true]);

        $import1 = Import::create(['type'=>'category-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);
        $import2 = Import::create(['type'=>'category-export','file_path'=>'','file_name'=>'','status'=>'pending','total_rows'=>0,'created_by'=>$user->id]);

        $job1 = new ExportCategoriesJob($import1->id);
        $job2 = new ExportCategoriesJob($import2->id);
        $job1->handle();
        $job2->handle();

        $import1->refresh(); $import2->refresh();
        $this->assertEquals('completed', $import1->status);
        $this->assertEquals('completed', $import2->status);
        $this->assertNotEquals($import1->file_path, $import2->file_path, 'filenames must be unique per import');
        $this->assertStringContainsString((string)$import1->id, $import1->file_path);
        $this->assertStringContainsString((string)$import2->id, $import2->file_path);
        Storage::disk('imports')->assertExists($import1->file_path);
        Storage::disk('imports')->assertExists($import2->file_path);
        $this->assertNotEquals(Storage::disk('imports')->path($import1->file_path), Storage::disk('imports')->path($import2->file_path));
    }

    public function test_download_security_non_owner_cannot_download(): void
    {
        Storage::fake('imports');
        $owner = User::create(['name'=>'Owner','email'=>'owner-'.uniqid().'@test.com','password'=>bcrypt('p'),'is_active'=>true]);
        $other = User::create(['name'=>'Other','email'=>'other-'.uniqid().'@test.com','password'=>bcrypt('p'),'is_active'=>true]);

        $import = Import::create(['type'=>'category-export','file_path'=>'test.xlsx','file_name'=>'test.xlsx','status'=>'completed','total_rows'=>1,'created_by'=>$owner->id]);
        Storage::disk('imports')->put('test.xlsx', 'content');

        // other user should get 403 (policy denies) not 200
        $this->actingAs($other, 'sanctum');
        $response = $this->getJson("/api/v1/admin/categories/export/{$import->id}/download");
        $this->assertTrue(in_array($response->getStatusCode(), [403,404]), "expected 403/404 got ".$response->getStatusCode());
    }
}
