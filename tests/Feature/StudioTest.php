<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\StudioAsset;
use App\Services\StudioStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StudioTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('studio');
    }

    private function upload($user, array $fields, array $files)
    {
        return $this->actingAs($user)->post('/studio', $fields + ['files' => $files]);
    }

    public function test_upload_stores_image_thumbnail_and_record(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['category' => 'FINISHED_WORK', 'title' => 'TEST oak kitchen', 'tags' => 'oak'],
            [UploadedFile::fake()->image('kitchen.jpg', 1600, 1200)])->assertRedirect();

        $a = StudioAsset::sole();
        $this->assertSame('TEST oak kitchen', $a->title);
        $this->assertSame([1600, 1200, 'image/jpeg'], [$a->width, $a->height, $a->mime_type]);
        $this->assertMatchesRegularExpression('/^IMG-\d{4}-\d{6}$/', $a->asset_no);
        Storage::disk('studio')->assertExists([$a->path, $a->thumb_path]);
        $thumb = getimagesizefromstring(Storage::disk('studio')->get($a->thumb_path));
        $this->assertSame(480, max($thumb[0], $thumb[1]));
        $this->assertSame(1, DB::table('audit_log')->where(['table_name' => 'studio_assets', 'row_id' => $a->id])->count());

        $this->actingAs($admin)->get('/studio')->assertOk()->assertSee('TEST oak kitchen');
        $this->actingAs($admin)->get('/studio?v=list&f[]=finished_work')->assertOk()->assertSee($a->asset_no);
        $this->actingAs($admin)->get("/studio/{$a->id}")->assertOk()->assertSee('oak');
    }

    public function test_same_file_is_not_stored_twice_and_non_images_are_refused(): void
    {
        $admin = $this->admin();
        $file = UploadedFile::fake()->image('a.png', 300, 200);
        $this->upload($admin, ['category' => 'CATALOG'], [$file])->assertRedirect();
        $this->upload($admin, ['category' => 'CATALOG'], [$file])->assertSessionHas('ok', fn ($m) => str_contains($m, StudioAsset::sole()->asset_no));
        $this->assertSame(1, StudioAsset::count());

        $this->upload($admin, ['category' => 'CATALOG'], [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('files.0');
    }

    public function test_customer_photo_needs_customer_and_stays_private(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['category' => 'CLIENT_REFERENCE'], [UploadedFile::fake()->image('r.jpg')])
            ->assertSessionHasErrors('client_id');

        $a = Client::create(['business_name' => 'TEST A']);
        $b = Client::create(['business_name' => 'TEST B']);
        $this->upload($admin, ['category' => 'CLIENT_REFERENCE', 'client_id' => $a->id, 'title' => 'A ref'], [UploadedFile::fake()->image('r.jpg')]);
        $this->upload($admin, ['category' => 'FINISHED_WORK', 'title' => 'Done'], [UploadedFile::fake()->image('d.jpg', 20, 20)]);
        $private = StudioAsset::where('title', 'A ref')->sole();

        $this->actingAs($admin)->getJson("/studio/picker?client_id={$a->id}")->assertOk()->assertJsonCount(2);
        $this->actingAs($admin)->getJson("/studio/picker?client_id={$b->id}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.title', 'Done');

        // The database refuses another customer's photo even if the form is bypassed.
        $line = ['description' => 'x', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 10, 'studio_asset_id' => $private->id];
        $this->actingAs($admin)->from('/quotations/create')->post('/quotations', [
            'client_id' => $b->id, 'issue_date' => now()->toDateString(), 'discount_amount' => 0, 'lines' => [$line],
        ])->assertSessionHasErrors('rule');
        $this->assertSame(0, DB::table('quotations')->count());
    }

    public function test_quotation_line_shows_studio_image_and_used_image_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'TEST A']);
        $this->upload($admin, ['category' => 'FINISHED_WORK', 'title' => 'Wardrobe'], [UploadedFile::fake()->image('w.jpg')]);
        $img = StudioAsset::sole();

        $this->actingAs($admin)->post('/quotations', [
            'client_id' => $client->id, 'issue_date' => now()->toDateString(), 'discount_amount' => 0,
            'lines' => [['description' => 'Wardrobe', 'quantity' => 1, 'unit' => 'pc', 'unit_price' => 900, 'studio_asset_id' => $img->id]],
        ])->assertRedirect();
        $q = DB::table('quotations')->first();
        $this->assertSame($img->id, (int) DB::table('quotation_lines')->value('studio_asset_id'));

        $this->actingAs($admin)->get("/quotations/{$q->id}")->assertOk()->assertSee("/studio/{$img->id}/file/thumb", false);
        $this->actingAs($admin)->get("/quotations/{$q->id}/edit")->assertOk()->assertSee('value="'.$img->id.'"', false);

        // Someone who sees quotations but not the studio can load the picture, not browse the studio.
        $viewer = $this->userWith(['quotations.view']);
        $this->actingAs($viewer)->get("/studio/{$img->id}/file/thumb")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($viewer)->get('/studio')->assertForbidden();
        $this->actingAs($this->userWith(['clients.view']))->get("/studio/{$img->id}/file/full")->assertForbidden();

        $this->actingAs($admin)->delete("/studio/{$img->id}")->assertSessionHasErrors('rule');
        Storage::disk('studio')->assertExists($img->path);
    }

    public function test_unused_image_delete_removes_files_and_edit_keeps_file(): void
    {
        $admin = $this->admin();
        $this->upload($admin, ['category' => 'SITE', 'title' => 'Site'], [UploadedFile::fake()->image('s.jpg')]);
        $img = StudioAsset::sole();

        $this->actingAs($admin)->put("/studio/{$img->id}", ['title' => 'Site — hall', 'category' => 'SITE', 'tags' => 'hall'])->assertRedirect();
        $this->assertSame('Site — hall', $img->fresh()->title);

        $this->actingAs($admin)->delete("/studio/{$img->id}")->assertRedirect('/studio');
        Storage::disk('studio')->assertMissing([$img->path, $img->thumb_path]);
        $this->assertSame(0, StudioAsset::count());
    }

    public function test_viewer_cannot_upload_and_production_refuses_local_disk(): void
    {
        $viewer = $this->userWith(['studio.view']);
        $this->upload($viewer, ['category' => 'CATALOG'], [UploadedFile::fake()->image('a.jpg')])->assertForbidden();
        $this->actingAs($viewer)->get('/studio/create')->assertForbidden();

        $this->app['env'] = 'production';
        config(['filesystems.disks.studio.driver' => 'local']);
        $admin = $this->admin();
        $this->actingAs($admin)->get('/studio')->assertOk()->assertSee('مخزن الصور غير مربوط');
        try {
            app(StudioStorage::class)->store(UploadedFile::fake()->image('a.jpg'), ['title' => 'x', 'category' => 'CATALOG'], $admin->id);
            $this->fail('upload to a local disk in production must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('files', $e->errors());
        }
        Storage::disk('studio')->assertDirectoryEmpty('/');
        $this->assertSame(0, StudioAsset::count());
    }

    public function test_client_and_project_pages_show_their_images(): void
    {
        $admin = $this->admin();
        $client = Client::create(['business_name' => 'TEST A']);
        $this->upload($admin, ['category' => 'CLIENT_REFERENCE', 'client_id' => $client->id, 'title' => 'Ref'], [UploadedFile::fake()->image('r.jpg')]);

        $this->actingAs($admin)->get("/clients/{$client->id}")->assertOk()
            ->assertSee('/studio/'.StudioAsset::sole()->id.'/file/thumb', false)
            ->assertSee(route('studio.create', ['client_id' => $client->id]), false);
    }
}
