<?php

namespace App\Jobs\Sports;

use App\Services\Sports\SportsHomeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RebuildSportsHomeSnapshotJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 20;

    public function __construct()
    {
        $this->onQueue((string) config('services.sportsdb.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sports-home-rebuild'))
                ->expireAfter($this->timeout + 5)
                ->dontRelease(),
        ];
    }

    public function handle(SportsHomeService $home): void
    {
        $home->rebuild();
    }
}
