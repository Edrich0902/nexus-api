<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Food & Drink affinity maps (rule-based recommender)
    |--------------------------------------------------------------------------
    */
    'grape_to_categories' => [
        'cabernet sauvignon' => ['Beef', 'Lamb', 'Pork'],
        'merlot' => ['Beef', 'Chicken', 'Pasta'],
        'pinot noir' => ['Chicken', 'Pork', 'Seafood'],
        'chardonnay' => ['Chicken', 'Seafood', 'Pasta'],
        'sauvignon blanc' => ['Seafood', 'Vegetarian', 'Chicken'],
        'riesling' => ['Chicken', 'Pork', 'Seafood'],
        'syrah' => ['Beef', 'Lamb', 'Pork'],
        'shiraz' => ['Beef', 'Lamb', 'Pork'],
        'pinotage' => ['Beef', 'Lamb', 'Pork'],
        'chenin blanc' => ['Chicken', 'Seafood', 'Vegetarian'],
    ],

    'style_to_categories' => [
        'ipa' => ['Beef', 'Pork', 'Chicken'],
        'stout' => ['Beef', 'Dessert', 'Pork'],
        'pilsner' => ['Seafood', 'Chicken', 'Vegetarian'],
        'wheat beer' => ['Chicken', 'Seafood', 'Vegetarian'],
        'sour' => ['Seafood', 'Vegetarian', 'Dessert'],
        'porter' => ['Beef', 'Pork', 'Dessert'],
        'lager' => ['Chicken', 'Pork', 'Seafood'],
    ],

    'cuisine_to_wine_countries' => [
        'Italian' => ['Italy', 'France'],
        'French' => ['France'],
        'Mexican' => ['Spain', 'Chile', 'Argentina'],
        'Indian' => ['Germany', 'France', 'South Africa'],
        'Japanese' => ['Germany', 'France', 'USA'],
        'Chinese' => ['Germany', 'France'],
        'American' => ['USA', 'Australia'],
        'British' => ['France', 'Germany', 'South Africa'],
    ],
];
