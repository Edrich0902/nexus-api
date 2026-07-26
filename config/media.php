<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudinary credentials
    |--------------------------------------------------------------------------
    */
    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key' => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'secure' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Folder layout
    |--------------------------------------------------------------------------
    |
    | User uploads:  nexus/{env}/users/{userId}/{collection}/{ulid}
    | Mirrored:      nexus/{env}/mirror/{provider}/{key}
    |
    */
    'root_folder' => env('MEDIA_ROOT_FOLDER', 'nexus'),
    'env_segment' => env('MEDIA_ENV', env('APP_ENV', 'local')),

    'queue' => env('MEDIA_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Collections (user-facing folder segment)
    |--------------------------------------------------------------------------
    */
    'collections' => [
        'avatar' => [
            'max_bytes' => 5 * 1024 * 1024,
            // Named transform — required when Strict transformations is on.
            'incoming_transformation' => 'nexus_master_avatar',
        ],
        'cellar' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
        'kitchen' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
        'beer' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
        'library' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
        'vault' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
        'mirror' => [
            'max_bytes' => 10 * 1024 * 1024,
            'incoming_transformation' => 'nexus_master',
        ],
    ],

    'default_max_bytes' => 10 * 1024 * 1024,

    'allowed_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
    ],

    /*
    |--------------------------------------------------------------------------
    | Named transformations (created via media:sync-transformations)
    |--------------------------------------------------------------------------
    |
    | Delivery variants + incoming upload masters. With Strict transformations
    | enabled, only these named transforms may be used — never raw c_limit strings.
    |
    */
    'named_transformations' => [
        'nexus_thumb' => 'c_fill,g_auto,w_160,h_160,f_auto,q_auto',
        'nexus_card' => 'c_fill,g_auto,w_480,h_320,f_auto,q_auto',
        'nexus_hero' => 'c_limit,w_1280,f_auto,q_auto',
        'nexus_avatar' => 'c_fill,g_face,w_256,h_256,f_auto,q_auto',
        'nexus_master' => 'c_limit,w_2048,q_auto:good',
        'nexus_master_avatar' => 'c_limit,w_1024,q_auto:good',
    ],

    /*
    |--------------------------------------------------------------------------
    | Morph map aliases allowed for attach_to.type
    |--------------------------------------------------------------------------
    */
    'attachable' => [
        'user' => App\Models\User::class,
        'cellar_wine' => App\Models\Cellar\CellarWine::class,
        'kitchen_recipe' => App\Models\Kitchen\KitchenRecipe::class,
        'beer_beer' => App\Models\Beer\BeerBeer::class,
        'library_book' => App\Models\Library\LibraryBook::class,
        'library_catalog_book' => App\Models\LibraryCatalog\LibraryCatalogBook::class,
        'wine_catalog_wine' => App\Models\WineCatalog\WineCatalogWine::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream image mirroring
    |--------------------------------------------------------------------------
    |
    | mirror=true: upload a copy into Cloudinary when the image is persisted.
    | mealdb mirrors only on recipe save (never on browse) — handled in code.
    |
    */
    'mirrors' => [
        'wineapi' => [
            'enabled' => (bool) env('MEDIA_MIRROR_WINEAPI', true),
            'collection' => 'mirror',
        ],
        'unsplash' => [
            'enabled' => (bool) env('MEDIA_MIRROR_UNSPLASH', true),
            'collection' => 'vault',
        ],
        'mealdb' => [
            'enabled' => (bool) env('MEDIA_MIRROR_MEALDB', true),
            'on_save_only' => true,
            'collection' => 'mirror',
        ],
        'openlibrary' => [
            'enabled' => (bool) env('MEDIA_MIRROR_OPENLIBRARY', true),
            'collection' => 'mirror',
        ],
        'spotify' => [
            'enabled' => false,
        ],
        'github' => [
            'enabled' => false,
        ],
        'sportsdb' => [
            'enabled' => (bool) env('MEDIA_MIRROR_SPORTSDB', false),
            'collection' => 'mirror',
        ],
        'openf1' => [
            'enabled' => (bool) env('MEDIA_MIRROR_OPENF1', false),
            'collection' => 'mirror',
        ],
    ],

    'usage_cache_seconds' => (int) env('MEDIA_USAGE_CACHE_SEC', 1800),

    'unsplash' => [
        'access_key' => env('UNSPLASH_ACCESS_KEY'),
        'secret_key' => env('UNSPLASH_SECRET_KEY'),
        'base_url' => env('UNSPLASH_BASE_URL', 'https://api.unsplash.com'),
        'timeout' => (int) env('UNSPLASH_TIMEOUT', 12),
        'search_cache_seconds' => (int) env('UNSPLASH_SEARCH_CACHE_SEC', 300),
        'per_page' => 20,
    ],

];
