<?php

namespace Database\Factories;

use App\Models\Media\MediaAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $ulid = (string) Str::ulid();
        $folder = 'nexus/local/users/1/vault';

        return [
            'user_id' => User::factory(),
            'public_id' => $folder.'/'.$ulid,
            'asset_id' => fake()->uuid(),
            'folder' => $folder,
            'collection' => 'vault',
            'secure_url' => 'https://res.cloudinary.com/demo/image/upload/'.$folder.'/'.$ulid.'.jpg',
            'format' => 'jpg',
            'resource_type' => 'image',
            'bytes' => fake()->numberBetween(10_000, 500_000),
            'width' => 800,
            'height' => 600,
            'version' => 1,
            'etag' => fake()->md5(),
            'alt_text' => null,
            'source' => MediaAsset::SOURCE_UPLOAD,
            'source_provider' => null,
            'source_ref' => null,
            'source_meta' => null,
            'tags' => [],
        ];
    }
}
