<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Languages
    |--------------------------------------------------------------------------
    |
    | Languages supported by conversations and the FAQ chatbot. The default
    | is Bahasa Malaysia; FAQ lookups fall back to the fallback language
    | when no match exists in the visitor's language.
    |
    */

    'languages' => ['ms', 'en'],

    'default_language' => env('TDECHAT_DEFAULT_LANGUAGE', 'ms'),

    'fallback_language' => env('TDECHAT_FALLBACK_LANGUAGE', 'en'),

];
