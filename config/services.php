<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_id' => env('WHATSAPP_APP_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v20.0'),

        /*
        | Embedded Signup ("Connect WhatsApp" button). Businesses never see
        | Meta's console: the popup returns a code, the backend does the rest.
        | config_id comes from the Embedded Signup configuration on the Meta
        | app; the platform token is a System User token of the platform's
        | own Meta Business (used for credit-line sharing).
        */
        'embedded_signup_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
        'platform_token' => env('WHATSAPP_PLATFORM_TOKEN'),
        'credit_line_id' => env('WHATSAPP_CREDIT_LINE_ID'),
        'credit_line_currency' => env('WHATSAPP_CREDIT_LINE_CURRENCY', 'NGN'),
        // Local development only: lets owners paste a phone number id + token
        // by hand instead of going through Embedded Signup.
        'manual_connect' => (bool) env('WHATSAPP_MANUAL_CONNECT', false),
    ],

];
