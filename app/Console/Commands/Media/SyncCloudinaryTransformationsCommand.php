<?php

namespace App\Console\Commands\Media;

use App\Integrations\Cloudinary\CloudinaryClient;
use Illuminate\Console\Command;

class SyncCloudinaryTransformationsCommand extends Command
{
    protected $signature = 'media:sync-transformations';

    protected $description = 'Create or update Nexus named Cloudinary transformations and allow them under Strict mode';

    public function handle(CloudinaryClient $cloudinary): int
    {
        /** @var array<string, string> $transformations */
        $transformations = config('media.named_transformations', []);

        if ($transformations === []) {
            $this->warn('No named transformations configured in config/media.php.');

            return self::FAILURE;
        }

        foreach ($transformations as $name => $definition) {
            $this->info("Syncing {$name} => {$definition}");
            $cloudinary->upsertNamedTransformation($name, $definition);
        }

        $this->info('Named transformations synced. Enable Strict transformations in the Cloudinary console if not already on.');

        return self::SUCCESS;
    }
}
