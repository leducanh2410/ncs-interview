<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Image Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default driver used by Laravel's image manager.
    | Supported drivers are "gd" and "imagick".
    |
    */

    'default' => env('IMAGE_DRIVER', 'gd'),
];
