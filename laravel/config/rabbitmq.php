<?php

return [
    'host'     => env('RABBITMQ_HOST', 'localhost'),
    'port'     => (int) env('RABBITMQ_PORT', 5672),
    'user'     => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost'    => env('RABBITMQ_VHOST', '/'),

    'exchange' => env('RABBITMQ_EXCHANGE', 'vendas.events'),

    // DEAD-LETTER: mensagens rejeitadas sem reenfileirar (nack com requeue = false) vão para a fila
    // "<fila><sufixo>" através desta exchange. Cada fila tem a sua própria DLQ.
    'dlx'        => env('RABBITMQ_DLX', 'vendas.dlx'),
    'dlq_sufixo' => '.dlq',

    'queues' => [
        'processar_estoque',
        'enviar_email',
        'gerar_nota',
    ],
];
