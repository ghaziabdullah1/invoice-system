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
    'stripe' => [
        'key'             => env('STRIPE_KEY'),
        'secret'          => env('STRIPE_SECRET'),
        'webhook_secret'  => env('STRIPE_WEBHOOK_SECRET'),
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


    'anthropic' => [
        'key'   => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-5-5'),
    ],

    'whatsapp' => [
        'token'           => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token'    => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret'      => env('WHATSAPP_APP_SECRET'),
        'graph_version'   => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    ],


    'ai_agent' => [
        'auto_reply'            => env('AI_AUTO_REPLY', false),   // false = مسودة فقط
        'daily_token_cap'       => (int) env('AI_DAILY_TOKEN_CAP', 200000),
        'hourly_limit_per_chat' => (int) env('AI_HOURLY_LIMIT', 10),
        'max_history'           => 6,
        'max_tokens'            => 300,
        'templates' => [
            'greeting' => [
                'ar' => 'أهلاً وسهلاً، كيف يمكنني مساعدتك؟',
                'en' => 'Welcome! How can I help you?',
            ],
            'thanks' => [
                'ar' => 'العفو، نحن في خدمتك.',
                'en' => "You're welcome, we're here to help.",
            ],
            'unsupported' => [
                'ar' => 'نستقبل الرسائل النصية فقط حالياً، فضلاً اكتب استفسارك.',
                'en' => 'We only accept text messages for now, please type your question.',
            ],
            'busy' => [
                'ar' => 'شكراً لتواصلك، سيرد عليك أحد موظفينا قريباً.',
                'en' => 'Thank you for contacting us, one of our team will reply shortly.',
            ],
        ],
    ],

];
