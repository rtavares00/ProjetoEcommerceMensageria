<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;

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

        // DLX = DEAD LETTER EXCHANGE: PARA ONDE VÃO AS MENSAGENS REJEITADAS. TIPO direct: A routing key ESCOLHE A DLQ DE CADA FILA
        $channel->exchange_declare(
            $config['dlx'], // NOME DA EXCHANGE DE MENSAGENS MORTAS (vendas.dlx)
            'direct', // TIPO: ENTREGA SÓ À FILA CUJO BINDING TEM A MESMA routing key (UMA DLQ POR FILA)
            false, // (passive) false = CRIA SE NÃO EXISTIR
            true, // (durable) SOBREVIVE A UM RESTART DO RABBITMQ
            false // (auto_delete) NÃO APAGA QUANDO A ÚLTIMA FILA DESVINCULAR
        );
        $this->info("Exchange '{$config['dlx']}' (direct, durável) declarada. \n");

        foreach($config['queues'] as $queue):

            // A DLQ É UMA FILA COMUM, DURÁVEL: GUARDA AS MENSAGENS REJEITADAS PARA ANÁLISE E REPROCESSAMENTO
            $dlq = $queue . $config['dlq_sufixo'];

            $channel->queue_declare( // DECLARANDO A DLQ DESTA FILA
                $dlq, //NOME DA DLQ (processar_estoque.dlq, enviar_email.dlq, gerar_nota.dlq)
                false, // (passive) false = CRIA SE NÃO EXISTIR
                true, // (durable) A FILA SOBREVIVE A UM RESTART DO RABBITMQ
                false, // (exclusive) false = QUALQUER CONEXÃO PODE LER (EX.: UM COMANDO DE REPROCESSAMENTO)
                false // (auto_delete) NÃO APAGA A FILA QUANDO O ÚLTIMO CONSUMIDOR DESCONECTAR
            );

            $channel->queue_bind( // VINCULA A DLQ À DLX
                $dlq, //NOME DA DLQ QUE RECEBERÁ AS MENSAGENS MORTAS
                $config['dlx'], //NOME DA DLX (vendas.dlx)
                $queue //routing key = NOME DA FILA DE ORIGEM: SÓ AS MENSAGENS MORTAS DESTA FILA CHEGAM A ESTA DLQ
            );
            $this->info("DLQ '{$dlq}' (durável) declarada e vinculada à '{$config['dlx']}'. \n");

            try {
                $channel->queue_declare( // DECLARANDO AS FILAS
                    $queue, //NOME DA FILA (processar_estoque, enviar_email, gerar_nota)
                    false, // (passive) false = CRIA SE NÃO EXISTIR; true = APENAS VERIFICA SE EXISTE
                    true, // (durable) A FILA SOBREVIVE A UM RESTART DO RABBITMQ
                    false, // (exclusive) false = VÁRIAS CONEXÕES PODEM USAR A FILA (WORKERS); true = SÓ A CONEXÃO QUE A CRIOU
                    false, // (auto_delete) NÃO APAGA A FILA QUANDO O ÚLTIMO CONSUMIDOR DESCONECTAR
                    false, // (nowait) false = ESPERA A CONFIRMAÇÃO DO RABBITMQ
                    new AMQPTable(array( // (arguments) CONFIGURAÇÕES EXTRAS DA FILA
                        'x-dead-letter-exchange' => $config['dlx'], // PARA ONDE VAI A MENSAGEM REJEITADA (nack com requeue = false)
                        'x-dead-letter-routing-key' => $queue // routing key USADA NA DLX: ESCOLHE A DLQ DESTA FILA
                    ))
                );
            } catch (AMQPProtocolChannelException $e) {
                // 406 PRECONDITION_FAILED: A FILA JÁ EXISTE COM OUTROS ARGUMENTOS. OS ARGUMENTOS DE UMA FILA NÃO PODEM SER ALTERADOS
                if($e->amqp_reply_code === 406):
                    $this->error("A fila '{$queue}' já existe sem a configuração de dead-letter e os argumentos de uma fila não podem ser alterados.");
                    $this->error("Apague-a e rode o setup novamente: rabbitmqctl delete_queue {$queue} \n");
                    return self::FAILURE;
                endif;
                throw $e;
            }

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
