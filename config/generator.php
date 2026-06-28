<?php

return [
    'default_connection' => env('GENERATOR_DEFAULT_CONNECTION'), // null in package; app overrides
    'generate_migrations' => true,                               // generic default ON
    'schemas_path' => null,                                      // app overrides
];
