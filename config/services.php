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

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY', env('CLAUDE_API_KEY')),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
    ],

    'email' => [
        // When true, replies are written to the Laravel log instead of being
        // sent over SMTP (and the IMAP "Sent" append is skipped). For testing.
        'log_only' => env('EMAIL_LOG_ONLY', false),
    ],

    'claude_code' => [
        // Headless Claude Code runs against a project's repository for a ticket.
        'binary' => env('CLAUDE_CODE_BINARY', 'claude'),
        'permission_mode' => env('CLAUDE_CODE_PERMISSION_MODE', 'plan'), // read-only by default
        'timeout' => (int) env('CLAUDE_CODE_TIMEOUT', 600),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

];
