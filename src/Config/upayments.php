<?php

return [
    'api_key'           => env('UPAYMENTS_API_KEY', ''), // Your Upayments API key
    'api_url'           => env('UPAYMENTS_API_URL', 'https://sandboxapi.upaymentss.com/api/v1'),
    'logging_channel'   => env('UPAYMENTS_LOGGING_CHANNEL', 'stack'),
    'logging_enabled'   => env('UPAYMENTS_LOGGING_ENABLED', true),
];