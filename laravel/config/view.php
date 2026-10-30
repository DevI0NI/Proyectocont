<?php

return [
    'paths' => [
        resource_path('views'),
    ],

    'compiled' => env('VIEW_COMPILED_PATH', sys_get_temp_dir().DIRECTORY_SEPARATOR.'proyectocont-laravel'.DIRECTORY_SEPARATOR.'views'),
];
