<?php

namespace App\Services\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\User;

class SpotifyLibraryService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @param  list<string>  $uris
     */
    public function save(User $user, array $uris): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->put($connection, '/me/library', [
            'uris' => array_values($uris),
        ]);
    }

    /**
     * @param  list<string>  $uris
     */
    public function remove(User $user, array $uris): void
    {
        $connection = $this->spotify->requireConnection($user);
        $this->spotify->delete($connection, '/me/library', [
            'uris' => array_values($uris),
        ]);
    }

    /**
     * @param  list<string>  $uris
     * @return list<bool>
     */
    public function contains(User $user, array $uris): array
    {
        $connection = $this->spotify->requireConnection($user);
        $response = $this->spotify->get($connection, '/me/library/contains', [
            'uris' => implode(',', $uris),
        ]);

        $result = $response->json();

        return is_array($result) ? array_map('boolval', $result) : [];
    }
}
