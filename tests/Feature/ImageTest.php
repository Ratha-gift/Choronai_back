<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ImageTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pretend R2 is configured, with a fake disk instead of Cloudflare.
        config([
            'filesystems.disks.r2.bucket' => 'choronai-pos',
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.url' => null,
        ]);
        Storage::fake('r2');
    }

    /** A real 1×1 PNG (the test container has no GD to draw one). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='
        ));
    }

    public function test_product_image_is_stored_in_r2_and_served_by_the_api(): void
    {
        $this->actingAsUser('admin021');
        $response = $this->post('/api/v1/uploads/images', [
            'folder' => 'products',
            'image' => $this->png('beef.png'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = $response->json('path');
        $this->assertMatchesRegularExpression('#^products/[0-9a-f-]{36}\.png$#', $path);
        $this->assertSame('images/'.$path, $response->json('url'));
        Storage::disk('r2')->assertExists($path);

        $this->get('/api/v1/images/'.$path)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/api/v1/images/products/../secret.jpg')->assertNotFound();
    }

    public function test_upload_rules(): void
    {
        $this->post('/api/v1/uploads/images', [], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->actingAsUser('admin021');
        $this->post('/api/v1/uploads/images', [
            'folder' => 'somewhere',
            'image' => $this->png('a.jpg'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/v1/uploads/images', [
            'folder' => 'products',
            'image' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        // A cashier cannot add product photos, but can change their own profile photo.
        $this->actingAsUser('cashier01');
        $this->post('/api/v1/uploads/images', [
            'folder' => 'products',
            'image' => $this->png('a.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->post('/api/v1/uploads/images', [
            'folder' => 'user-profile',
            'image' => $this->png('me.png'),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_replaced_product_photo_is_deleted_from_r2(): void
    {
        $this->actingAsUser('admin021');
        $upload = fn () => $this->post('/api/v1/uploads/images', [
            'folder' => 'products',
            'image' => $this->png('p.jpg'),
        ], ['Accept' => 'application/json'])->json('path');
        $old = $upload();
        $new = $upload();
        $product = $this->product('MEAT-001');

        $this->patchJson("/api/v1/products/{$product->id}", ['image' => '/api/backend/images/'.$old])->assertOk();
        $this->patchJson("/api/v1/products/{$product->id}", ['image' => '/api/backend/images/'.$new])->assertOk();

        Storage::disk('r2')->assertMissing($old);
        Storage::disk('r2')->assertExists($new);
    }
}
