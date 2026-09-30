<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class PedidoProducer
{
    private $connection = null;
    private $channel = null;
    private $exchange = null;

    public function __construct()
    {
        //SETUP CONNECTION
        $config = config('rabbitmq'); // FAZ LEITURA DO ARQUIVO DE CONFIGURAÇÃO
        $this->connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost']
        ); // ABRE CONEXÃO COM O RABBITMQ
        $this->exchange = $config['exchange'];
        $this->channel = $this->connection->channel(); // ESTABELECE CANAL DE COMUNICAÇÃO
    }

    public function __destruct()
    {
        $this->closeConnection(); // 5. FECHA CHANNEL E CONEXÃO QUANDO O OBJETO DEIXA DE EXISTIR
    }

    private function closeConnection() : void
    {
        if($this->channel?->is_open()):
            $this->channel->close();
        endif;

        if($this->connection?->isConnected()):
            $this->connection->close();
        endif;
    }
    
    public function publicar(array $pedido) : void
    {
        // 1. ler config('rabbitmq') e abrir a conexão (FEITO NO CONSTRUTOR)
        // 2. abrir o channel (FEITO NO CONSTRUTOR)
        // 3. montar o AMQPMessage (json_encode + propriedades)
        $properties = array('content_type' => 'application/json', 'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT);
        $msg = new AMQPMessage(json_encode($pedido), $properties);
        
        // 4. $channel->basic_publish(...)
        $this->channel->basic_publish($msg, $this->exchange);
        // 5. fechar channel e conexão (FEITO NO DESTRUTOR)
    }
}
