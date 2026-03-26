<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Herd Sites Path
    |--------------------------------------------------------------------------
    |
    | The directory where Laravel Herd stores site projects. This is scanned
    | to discover installations that can be managed by the update tool.
    |
    */

    'path' => env('HERD_PATH', '/Users/'.get_current_user().'/Herd'),

];
