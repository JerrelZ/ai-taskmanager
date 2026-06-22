<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI features
    |--------------------------------------------------------------------------
    |
    | Master switch for the user-facing AI features in the email inbox
    | (AI draft reply, "summarise with AI", the per-thread AI summary line and
    | the AI category grouping/filter). Turn this off to hide them from the
    | front-end without removing the backend — flip it back on to restore.
    |
    | Note: the developer-facing Claude Code / prompt features stay visible;
    | those are gated separately by the `can_copy_prompt` user permission.
    |
    */

    'ai' => env('AI_FEATURES', true),

];
