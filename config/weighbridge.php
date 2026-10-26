<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weighbridge Application Settings
    |--------------------------------------------------------------------------
    */

    'token_ttl_hours' => (int) env('API_TOKEN_TTL', 168),

    'movement_ref_prefix' => env('MOVEMENT_REF_PREFIX', 'MV'),

    'default_weight_unit' => env('DEFAULT_WEIGHT_UNIT', 'kg'),

    'max_page_size' => (int) env('MAX_PAGE_SIZE', 100),

];
