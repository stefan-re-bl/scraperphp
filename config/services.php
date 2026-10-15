<?php

$verifiedScraperImage = 'gosom/google-maps-scraper@sha256:e205c02913c5a69c16fc2094b8e5b194a2f655166d2c1126d50548d216e6b1b2';
$scraperImage = env('GOOGLE_MAPS_SCRAPER_IMAGE', $verifiedScraperImage);
if ($scraperImage === 'gosom/google-maps-scraper:latest') {
    $scraperImage = $verifiedScraperImage;
}

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'enrichment' => [
        'max_response_bytes' => (int) env('LEAD_ENRICHMENT_MAX_RESPONSE_BYTES', 120000),
    ],

    'scraper' => [
        'timeout' => (int) env('GOOGLE_MAPS_SCRAPER_TIMEOUT', 600),
        'docker' => env('GOOGLE_MAPS_SCRAPER_USE_DOCKER', true),
        'binary' => env('GOOGLE_MAPS_SCRAPER_BIN', 'google-maps-scraper'),
        'image' => $scraperImage,
    ],
];
