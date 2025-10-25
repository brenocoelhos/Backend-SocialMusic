<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$result = [
    'vars' => [
        'DB_HOST' => getenv('DB_HOST') ?: 'NAO DEFINIDO',
        'DB_NAME' => getenv('DB_NAME') ?: 'NAO DEFINIDO',
        'DB_USER' => getenv('DB_USER') ?: 'NAO DEFINIDO',
        'DB_PASS_SET' => getenv('DB_PASS') ? 'SIM' : 'NAO'
    ]
];

echo json_encode($result, JSON_PRETTY_PRINT);
