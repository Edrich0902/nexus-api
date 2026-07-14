<?php

namespace App\Jobs\F1;

use App\Services\F1\F1HomeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RebuildF1HomeSnapshotJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('services.openf1.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('f1-rebuild-home'))
                ->releaseAfter(30)
                ->expireAfter(300),
        ];
    }

    public function handle(F1HomeService $home): void
    {
        $home->rebuild();
    }
}
