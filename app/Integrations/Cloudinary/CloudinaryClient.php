<?php

namespace App\Integrations\Cloudinary;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\UpstreamRateGate;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Exception\ApiError;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\UploadedFile;

class CloudinaryClient
{
    public const PROVIDER = 'cloudinary';

    private bool $configured = false;

    public function __construct(
        private readonly UpstreamRateGate $rateGate,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function upload(UploadedFile|string $file, array $options = []): array
    {
        $this->configure();
        $this->acquire();

        $path = $file instanceof UploadedFile
            ? $file->getRealPath()
            : $file;

        if ($path === false || $path === '') {
            throw new IntegrationException('[cloudinary] Invalid upload source.', 422);
        }

        try {
            /** @var array<string, mixed> $response */
            $response = (new UploadApi)->upload($path, $options)->getArrayCopy();

            return $response;
        } catch (ApiError $e) {
            throw new IntegrationException(
                '[cloudinary] Upload failed: '.$e->getMessage(),
                $e->getCode() > 0 ? (int) $e->getCode() : 502,
                previous: $e,
            );
        }
    }

    /**
     * Import a remote image URL into Cloudinary (bytes do not transit Nexus).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function uploadFromUrl(string $url, array $options = []): array
    {
        return $this->upload($url, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function destroy(string $publicId, array $options = []): array
    {
        $this->configure();
        $this->acquire();

        try {
            /** @var array<string, mixed> $response */
            $response = (new UploadApi)->destroy($publicId, $options)->getArrayCopy();

            return $response;
        } catch (ApiError $e) {
            throw new IntegrationException(
                '[cloudinary] Destroy failed: '.$e->getMessage(),
                $e->getCode() > 0 ? (int) $e->getCode() : 502,
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function usage(array $options = []): array
    {
        $this->configure();
        $this->acquire();

        try {
            /** @var array<string, mixed> $response */
            $response = (new AdminApi)->usage($options)->getArrayCopy();

            return $response;
        } catch (ApiError $e) {
            throw new IntegrationException(
                '[cloudinary] Usage fetch failed: '.$e->getMessage(),
                $e->getCode() > 0 ? (int) $e->getCode() : 502,
                previous: $e,
            );
        }
    }

    /**
     * Create or update a named transformation and allow it under Strict mode.
     */
    public function upsertNamedTransformation(string $name, string $definition): void
    {
        $this->configure();
        $this->acquire();

        $admin = new AdminApi;

        try {
            $admin->createTransformation($name, $definition);
        } catch (ApiError $e) {
            // Already exists — fall through to update.
            if (! str_contains(strtolower($e->getMessage()), 'already exists')
                && ! str_contains(strtolower($e->getMessage()), 'name already')) {
                // Try update path for other conflict shapes; rethrow if update also fails.
            }
        }

        try {
            $admin->updateTransformation($name, [
                'unsafe_update' => $definition,
                'allowed_for_strict' => true,
            ]);
        } catch (ApiError $e) {
            throw new IntegrationException(
                '[cloudinary] Failed to sync transformation '.$name.': '.$e->getMessage(),
                $e->getCode() > 0 ? (int) $e->getCode() : 502,
                previous: $e,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function listResources(array $options = []): array
    {
        $this->configure();
        $this->acquire();

        try {
            /** @var array<string, mixed> $response */
            $response = (new AdminApi)->assets($options)->getArrayCopy();

            return $response;
        } catch (ApiError $e) {
            throw new IntegrationException(
                '[cloudinary] List resources failed: '.$e->getMessage(),
                $e->getCode() > 0 ? (int) $e->getCode() : 502,
                previous: $e,
            );
        }
    }

    private function configure(): void
    {
        if ($this->configured) {
            return;
        }

        $cloudName = (string) config('media.cloudinary.cloud_name');
        $apiKey = (string) config('media.cloudinary.api_key');
        $apiSecret = (string) config('media.cloudinary.api_secret');

        if ($cloudName === '' || $apiKey === '' || $apiSecret === '') {
            throw new IntegrationException(
                '[cloudinary] Missing CLOUDINARY_CLOUD_NAME / API_KEY / API_SECRET.',
                503,
            );
        }

        Configuration::instance([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => [
                'secure' => (bool) config('media.cloudinary.secure', true),
            ],
        ]);

        $this->configured = true;
    }

    private function acquire(): void
    {
        $maxWait = config('services.rate_limits.cloudinary.max_wait_seconds');

        $this->rateGate->acquire(
            self::PROVIDER,
            is_numeric($maxWait) ? (int) $maxWait : null,
        );
    }
}
