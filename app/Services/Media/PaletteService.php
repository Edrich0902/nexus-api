<?php

namespace App\Services\Media;

use App\Jobs\Media\ExtractImagePaletteJob;
use App\Models\Media\ImagePalette;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Colour palettes for artwork, keyed by image URL. Lookups never block on
 * network I/O: a missing palette is queued and returned as pending.
 */
class PaletteService
{
    public function __construct(
        private readonly PaletteExtractor $extractor,
    ) {}

    public function isAllowed(?string $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 1024) {
            return false;
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        foreach ((array) config('media.palette.allowed_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.'.$allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forUrl(?string $url): ?array
    {
        if (! $this->isAllowed($url)) {
            return null;
        }

        return $this->forUrls([(string) $url])[(string) $url] ?? null;
    }

    /**
     * Ready palettes for many URLs in one query; unknown URLs are queued.
     *
     * @param  list<string|null>  $urls
     * @return array<string, array<string, mixed>|null>
     */
    public function forUrls(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls, fn ($u) => $this->isAllowed($u))));
        if ($urls === []) {
            return [];
        }

        $byHash = [];
        foreach ($urls as $url) {
            $byHash[ImagePalette::hashFor($url)] = $url;
        }

        $rows = ImagePalette::query()
            ->whereIn('url_hash', array_keys($byHash))
            ->get(['id', 'url_hash', 'status', 'palette', 'attempts', 'updated_at'])
            ->keyBy('url_hash');

        $result = [];
        foreach ($byHash as $hash => $url) {
            $row = $rows->get($hash);
            if ($row === null) {
                $this->queue($hash, $url);
                $result[$url] = null;

                continue;
            }

            if ($row->status === ImagePalette::STATUS_FAILED && $this->canRetry($row)) {
                $this->queue($hash, $url);
            }

            $result[$url] = $row->status === ImagePalette::STATUS_READY ? $row->palette : null;
        }

        return $result;
    }

    /**
     * @return array{status: string, palette: array<string, mixed>|null}
     */
    public function status(string $url): array
    {
        $palette = $this->forUrl($url);
        if ($palette !== null) {
            return ['status' => ImagePalette::STATUS_READY, 'palette' => $palette];
        }

        $row = ImagePalette::query()->where('url_hash', ImagePalette::hashFor($url))->first(['status']);

        return ['status' => $row?->status ?? ImagePalette::STATUS_PENDING, 'palette' => null];
    }

    /**
     * Fetch and analyse the image. Called from the queued job.
     */
    public function extract(ImagePalette $row): void
    {
        if (! $this->isAllowed($row->url)) {
            $row->forceFill(['status' => ImagePalette::STATUS_FAILED])->save();

            return;
        }

        $row->increment('attempts');

        try {
            $palette = $this->extractor->extract(
                $this->download($row->url),
                (int) config('media.palette.max_pixels', 24_000_000),
            );
            $row->forceFill(['status' => ImagePalette::STATUS_READY, 'palette' => $palette])->save();
        } catch (Throwable $e) {
            Log::info('media.palette.failed', ['id' => $row->id, 'reason' => $e->getMessage()]);
            $row->forceFill(['status' => ImagePalette::STATUS_FAILED])->save();
        }
    }

    private function download(string $url): string
    {
        $max = (int) config('media.palette.max_bytes', 5 * 1024 * 1024);

        $response = Http::timeout((int) config('media.palette.timeout', 6))
            ->withOptions(['allow_redirects' => false, 'stream' => true])
            ->accept('image/*')
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        $length = (int) $response->header('Content-Length');
        if ($length > $max) {
            throw new RuntimeException('Image exceeds size limit.');
        }

        $body = $response->toPsrResponse()->getBody();
        $bytes = '';
        while (! $body->eof()) {
            $bytes .= $body->read(65536);
            if (strlen($bytes) > $max) {
                throw new RuntimeException('Image exceeds size limit.');
            }
        }

        return $bytes;
    }

    private function queue(string $hash, string $url): void
    {
        ImagePalette::query()->insertOrIgnore([
            'url_hash' => $hash,
            'url' => $url,
            'status' => ImagePalette::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ExtractImagePaletteJob::dispatch($hash);
    }

    private function canRetry(ImagePalette $row): bool
    {
        return $row->attempts < (int) config('media.palette.max_attempts', 3)
            && $row->updated_at !== null
            && $row->updated_at->lt(now()->subHour());
    }
}
