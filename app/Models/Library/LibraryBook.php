<?php

namespace App\Models\Library;

use App\Models\Concerns\HasCoverImage;
use App\Models\LibraryCatalog\LibraryCatalogBook;
use App\Models\User;
use Database\Factories\LibraryBookFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LibraryBook extends Model
{
    /** @use HasFactory<LibraryBookFactory> */
    use HasCoverImage;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_WANT = 'want';

    public const STATUS_READING = 'reading';

    public const STATUS_READ = 'read';

    public const STATUSES = [
        self::STATUS_WANT,
        self::STATUS_READING,
        self::STATUS_READ,
    ];

    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_NO_MATCH = 'no_match';

    protected $fillable = [
        'user_id',
        'library_catalog_book_id',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'title',
        'authors',
        'isbn',
        'status',
        'rating',
        'notes',
        'started_at',
        'finished_at',
        'match_status',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'started_at' => 'date',
            'finished_at' => 'date',
        ];
    }

    protected static function newFactory(): LibraryBookFactory
    {
        return LibraryBookFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogBook(): BelongsTo
    {
        return $this->belongsTo(LibraryCatalogBook::class, 'library_catalog_book_id');
    }
}
