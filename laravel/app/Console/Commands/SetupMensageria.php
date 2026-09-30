<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;

#[Signature('rabbitmq:setup')]
#[Description('Este Comando Tem como Objetivo Criar de forma automatizada a estrutura de Exchanges e Filas do RabbitMQ')]
class SetupMensageria extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $config = config('rabbitmq'); // FAZ LEITURA DO ARQUIVO DE CONFIGURAÇÃO
        
        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost']
        ); // ABRE CONEXÃO COM O RABBITMQ

        $this->info("Conexão com o RABBIT MQ ABERTA \n");
        sleep(5);

        $channel = $connection->channel(); // ESTABELECE CANAL DE COMUNICAÇÃO
        $this->info("Estabelecido o Canal de Comunicação \n");
        sleep(5);

        $channel->exchange_declare( // EXCHANGE É O CARTEIRO
            $config['exchange'], //NOME DA EXCHANGE (vendas.events)
            'fanout', //TIPO: REPLICA A MENSAGEM PARA TODAS AS FILAS VINCULADAS
            false, // (passive) false = CRIA SE NÃO EXISTIR; true = APENAS VERIFICA SE EXISTE E DÁ ERRO SE NÃO
            true, // (durable) SOBREVIVE A UM RESTART DO RABBITMQ
            false // (auto_delete) NÃO APAGA A EXCHANGE QUANDO A ÚLTIMA FILA DESVINCULAR
        );

        $this->info("Exchange '{$config['exchange']}' (fanout, durável) declarada. \n");
        sleep(10);

        foreach($config['queues'] as $queue):
            
            $channel->queue_declare( // DECLARANDO AS FILAS
                $queue, //NOME DA FILA (processar_estoque, enviar_email, gerar_nota)
                false, // (passive) false = CRIA SE NÃO EXISTIR; true = APENAS VERIFICA SE EXISTE
                true, // (durable) A FILA SOBREVIVE A UM RESTART DO RABBITMQ
                false, // (exclusive) false = VÁRIAS CONEXÕES PODEM USAR A FILA (WORKERS); true = SÓ A CONEXÃO QUE A CRIOU
                false // (auto_delete) NÃO APAGA A FILA QUANDO O ÚLTIMO CONSUMIDOR DESCONECTAR
            );

            $channel->queue_bind( //BIND = VINCULAR -> VINCULANDO AS FILAS COM AS EXCHANGES
                $queue, //NOME DA FILA QUE RECEBERÁ AS MENSAGENS DA EXCHANGE
                $config['exchange'] //NOME DA EXCHANGE DE ORIGEM (vendas.events)
                // OS DEMAIS PARÂMETROS FICAM NO PADRÃO: routing_key = '' (O FANOUT IGNORA A ROUTING KEY), nowait = false, arguments = [], ticket = null
            );
            $this->info("Fila '{$queue}' (durável) declarada e vinculada. \n");
            sleep(10);
        endforeach;

        $channel->close();
        $connection->close();

        $this->info("Operação Finalizada com Sucesso \n");
        return self::SUCCESS;
    }
}
