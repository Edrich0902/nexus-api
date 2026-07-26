<?php

namespace Tests\Feature\Api\V1\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use App\Integrations\Unsplash\UnsplashIntegration;
use App\Models\Cellar\CellarWine;
use App\Models\Media\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'media.cloudinary.cloud_name' => 'demo',
            'media.cloudinary.api_key' => 'key',
            'media.cloudinary.api_secret' => 'secret',
            'media.env_segment' => 'testing',
        ]);
    }

    public function test_upload_creates_media_asset_and_can_attach_to_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mockCloudinaryUpload();

        $response = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
            'collection' => 'avatar',
            'attach_to' => ['type' => 'user', 'id' => $user->id],
            'role' => 'cover',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('collection', 'avatar')
            ->assertJsonPath('source', 'upload')
            ->assertJsonStructure(['id', 'public_id', 'url', 'media' => ['id', 'public_id', 'url']]);

        $user->refresh();
        $this->assertNotNull($user->media_asset_id);
        $this->assertNotNull($user->media_public_id);
        $this->assertNotNull($user->media_url);
    }

    public function test_from_url_import_persists_unsplash_source(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mockCloudinaryUpload('nexus/testing/users/'.$user->id.'/vault/unsplash-1');

        $this->mock(UnsplashIntegration::class, function (MockInterface $mock): void {
            $mock->shouldReceive('trackDownload')->once()->with('https://api.unsplash.com/photos/abc/download');
        });

        $response = $this->postJson('/api/v1/media/from-url', [
            'url' => 'https://images.unsplash.com/photo-1',
            'collection' => 'vault',
            'source' => 'unsplash',
            'source_ref' => 'abc',
            'source_meta' => [
                'photographer' => 'Jane Doe',
                'profile_url' => 'https://unsplash.com/@jane',
            ],
            'unsplash_download_location' => 'https://api.unsplash.com/photos/abc/download',
        ]);

        $response->assertCreated()
            ->assertJsonPath('source', 'unsplash')
            ->assertJsonPath('source_ref', 'abc');
    }

    public function test_media_list_is_scoped_and_paginated(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        MediaAsset::factory()->create(['user_id' => $user->id, 'collection' => 'vault']);
        MediaAsset::factory()->create(['user_id' => $other->id, 'collection' => 'vault']);
        MediaAsset::factory()->create(['user_id' => null, 'collection' => 'mirror', 'source' => 'mirror']);

        $this->getJson('/api/v1/media?collection=vault')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_cannot_access_another_users_media(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $asset = MediaAsset::factory()->create(['user_id' => $other->id]);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/media/'.$asset->id)->assertNotFound();
    }

    public function test_attach_and_detach_cellar_wine_cover(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::factory()->create(['user_id' => $user->id]);
        $asset = MediaAsset::factory()->create(['user_id' => $user->id, 'collection' => 'cellar']);

        $this->postJson('/api/v1/media/'.$asset->id.'/attach', [
            'type' => 'cellar_wine',
            'id' => $wine->id,
            'role' => 'cover',
        ])->assertOk();

        $wine->refresh();
        $this->assertSame($asset->id, $wine->media_asset_id);
        $this->assertSame($asset->public_id, $wine->media_public_id);

        $this->deleteJson('/api/v1/media/'.$asset->id.'/attach', [
            'type' => 'cellar_wine',
            'id' => $wine->id,
            'role' => 'cover',
        ])->assertOk();

        $wine->refresh();
        $this->assertNull($wine->media_asset_id);
    }

    public function test_delete_returns_conflict_when_attached_without_force(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $asset = MediaAsset::factory()->create(['user_id' => $user->id]);
        $wine = CellarWine::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/media/'.$asset->id.'/attach', [
            'type' => 'cellar_wine',
            'id' => $wine->id,
        ])->assertOk();

        $this->deleteJson('/api/v1/media/'.$asset->id)
            ->assertStatus(409)
            ->assertJsonStructure(['attachments']);

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'deleted_at' => null]);
    }

    public function test_force_delete_removes_asset_from_cloudinary_and_db(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $asset = MediaAsset::factory()->create(['user_id' => $user->id]);
        $wine = CellarWine::factory()->create(['user_id' => $user->id]);

        $this->postJson('/api/v1/media/'.$asset->id.'/attach', [
            'type' => 'cellar_wine',
            'id' => $wine->id,
        ])->assertOk();

        $this->mock(CloudinaryClient::class, function (MockInterface $mock) use ($asset): void {
            $mock->shouldReceive('destroy')
                ->once()
                ->with($asset->public_id, \Mockery::type('array'))
                ->andReturn(['result' => 'ok']);
        });

        $this->deleteJson('/api/v1/media/'.$asset->id.'?force=1')->assertNoContent();

        $this->assertSoftDeleted('media_assets', ['id' => $asset->id]);
        $wine->refresh();
        $this->assertNull($wine->media_asset_id);
    }

    public function test_upload_rejects_invalid_mime(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            'collection' => 'vault',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_usage_endpoint_returns_normalized_snapshot(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->mock(CloudinaryClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('usage')->once()->andReturn([
                'plan' => 'Free',
                'last_updated' => '2026-07-26',
                'credits' => ['usage' => 2.5, 'limit' => 25, 'used_percent' => 10],
                'storage' => ['usage' => 1000, 'credits_usage' => 0.001],
                'bandwidth' => ['usage' => 2000, 'credits_usage' => 0.002],
                'transformations' => ['usage' => 10, 'credits_usage' => 0.01],
                'objects' => ['usage' => 5],
                'resources' => 2,
                'derived_resources' => 3,
                'requests' => 40,
            ]);
        });

        $this->getJson('/api/v1/media/usage')
            ->assertOk()
            ->assertJsonPath('plan', 'Free')
            ->assertJsonPath('credits.limit', 25)
            ->assertJsonPath('resources', 2)
            ->assertJsonPath('derived_resources', 3)
            ->assertJsonPath('objects', 5)
            ->assertJsonStructure(['storage', 'bandwidth', 'transformations', 'fetched_at']);
    }

    private function mockCloudinaryUpload(?string $publicId = null): void
    {
        $this->mock(CloudinaryClient::class, function (MockInterface $mock) use ($publicId): void {
            $mock->shouldReceive('upload')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function ($file, array $options = []) use ($publicId) {
                    $id = $publicId ?? (string) ($options['public_id'] ?? 'nexus/testing/users/1/vault/ulid');

                    return [
                        'public_id' => $id,
                        'asset_id' => 'asset_123',
                        'secure_url' => 'https://res.cloudinary.com/demo/image/upload/'.$id.'.jpg',
                        'format' => 'jpg',
                        'resource_type' => 'image',
                        'bytes' => 12345,
                        'width' => 400,
                        'height' => 400,
                        'version' => 1,
                        'etag' => 'etag123',
                        'folder' => $options['folder'] ?? null,
                        'asset_folder' => $options['asset_folder'] ?? null,
                    ];
                });

            $mock->shouldReceive('uploadFromUrl')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function (string $url, array $options = []) use ($publicId) {
                    unset($url);
                    $id = $publicId ?? (string) ($options['public_id'] ?? 'nexus/testing/users/1/vault/ulid');

                    return [
                        'public_id' => $id,
                        'asset_id' => 'asset_456',
                        'secure_url' => 'https://res.cloudinary.com/demo/image/upload/'.$id.'.jpg',
                        'format' => 'jpg',
                        'resource_type' => 'image',
                        'bytes' => 54321,
                        'width' => 800,
                        'height' => 600,
                        'version' => 1,
                        'etag' => 'etag456',
                        'folder' => $options['folder'] ?? null,
                        'asset_folder' => $options['asset_folder'] ?? null,
                    ];
                });
        });
    }
}
