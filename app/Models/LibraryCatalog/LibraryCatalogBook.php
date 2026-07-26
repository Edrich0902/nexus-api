<?php

namespace App\Models\LibraryCatalog;

use App\Models\Concerns\HasCoverImage;
use App\Models\Library\LibraryBook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LibraryCatalogBook extends Model
{
    use HasCoverImage;

    protected $fillable = [
        'ol_work_key',
        'ol_edition_key',
        'title',
        'authors',
        'isbn_10',
        'isbn_13',
        'publish_year',
        'page_count',
        'description',
        'cover_i',
        'cover_url',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'authors' => 'array',
            'publish_year' => 'integer',
            'page_count' => 'integer',
            'cover_i' => 'integer',
            'raw' => 'array',
        ];
    }

    public function books(): HasMany
    {
        return $this->hasMany(LibraryBook::class, 'library_catalog_book_id');
    }

    public function authorsLabel(): ?string
    {
        if (! is_array($this->authors) || $this->authors === []) {
            return null;
        }

        return implode(', ', array_map(static fn ($a) => (string) $a, $this->authors));
    }
}
