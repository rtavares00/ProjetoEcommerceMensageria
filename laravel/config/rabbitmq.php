<?php

return [
    'host'     => env('RABBITMQ_HOST', 'localhost'),
    'port'     => (int) env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),

    'exchange' => env('RABBITMQ_EXCHANGE', 'vendas.events'),

    'queues' => [
        'processar_estoque',
        'enviar_email',
        'gerar_nota',
    ],
];
