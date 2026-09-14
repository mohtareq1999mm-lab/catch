<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cursor Pagination
    |--------------------------------------------------------------------------
    |
    | Enables opt-in cursor pagination on Product listings via the
    | `pagination=cursor` query parameter. When disabled, requests for cursor
    | pagination are rejected with a 422 response. Offset pagination remains
    | fully functional regardless of this flag.
    |
    */

    'enabled' => env('CURSOR_PAGINATION_ENABLED', false),

];
