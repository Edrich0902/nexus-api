<?php

namespace App\Jobs\Media;

use App\Models\Media\ImagePalette;
use App\Services\Media\PaletteService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExtractImagePaletteJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $urlHash)
    {
        $this->onQueue((string) config('media.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return $this->urlHash;
    }

    public function handle(PaletteService $palettes): void
    {
        $row = ImagePalette::query()->where('url_hash', $this->urlHash)->first();
        if ($row === null || $row->status === ImagePalette::STATUS_READY) {
            return;
        }

        $palettes->extract($row);
    }
}
