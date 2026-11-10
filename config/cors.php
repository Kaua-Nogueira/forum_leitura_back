<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Permite requisições de qualquer origem (Easypanel, domínios customizados,
    | localhost) com todos os cabeçalhos de autenticação Bearer e RBAC.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'up', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
