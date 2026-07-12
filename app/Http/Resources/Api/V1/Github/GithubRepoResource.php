<?php

namespace App\Http\Resources\Api\V1\Github;

use App\Models\Github\GithubRepo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GithubRepo */
class GithubRepoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->github_id,
            'owner' => $this->owner_login,
            'name' => $this->name,
            'full_name' => $this->full_name,
            'private' => $this->private,
            'default_branch' => $this->default_branch,
            'html_url' => $this->html_url,
            'description' => $this->description,
            'pushed_at' => $this->pushed_at?->toIso8601String(),
            'language' => $this->language,
        ];
    }
}
