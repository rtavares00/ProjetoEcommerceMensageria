<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

abstract class WorkerBase extends Command
{
    // CADA WORKER INFORMA DE QUAL FILA CONSOME
    abstract protected function fila() : string;

    // CADA WORKER FAZ O SEU TRABALHO. RETORNAR NORMALMENTE = SUCESSO; LANÇAR EXCEÇÃO = FALHA.
    // QUEM CHAMA ack() E nack() É SEMPRE A CLASSE BASE, NUNCA O processar()
    abstract protected function processar(array $pedido) : void;

    public function handle() : int
    {
        $config = config('rabbitmq'); // FAZ LEITURA DO ARQUIVO DE CONFIGURAÇÃO
        $fila = $this->fila();

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost']
        ); // ABRE CONEXÃO COM O RABBITMQ

        $this->info("Conexão com o RABBIT MQ ABERTA \n");

        $channel = $connection->channel(); // ESTABELECE CANAL DE COMUNICAÇÃO
        $this->info("Estabelecido o Canal de Comunicação \n");

        $channel->basic_qos(
            0, //prefetch_size	0	Sem limite em bytes (quase sempre 0).
            1,  //prefetch_count	1	O worker recebe 1 mensagem por vez; só recebe a próxima depois do ack.
            false //a_global	false	O limite vale por consumidor, não por canal inteiro.
        );

        // O CALLBACK PRECISA EXISTIR ANTES DO basic_consume, POIS ELE É PASSADO COMO PARÂMETRO
        $callback = function(AMQPMessage $msg) use ($fila)
        {
            $pedido = json_decode($msg->getBody(), true);

            // 1. VALIDAR O QUE CHEGOU: MENSAGEM INVÁLIDA NUNCA VAI FUNCIONAR, ENTÃO NÃO ADIANTA RECOLOCAR NA FILA
            if(!is_array($pedido) || empty($pedido['_id']) || empty($pedido['itens'])):
                Log::warning("Mensagem inválida descartada na fila {$fila}", array('corpo' => $msg->getBody()));
                $this->error("Mensagem inválida descartada");
                $msg->nack(false); // requeue = false -> DESCARTA (AINDA NÃO EXISTE DLQ)
                return;
            endif;

            try {
                $this->info("Pedido {$pedido['_id']} recebido" . ($msg->isRedelivered() ? ' (REENTREGA)' : ' (PRIMEIRA VEZ QUE CHEGOU)') . "\n");

                // 2. O TRABALHO ESPECÍFICO DE CADA WORKER (CLASSE FILHA)
                $this->processar($pedido);

                $msg->ack(); // SÓ CONFIRMA DEPOIS DE PROCESSAR COM SUCESSO
                $this->info("Pedido {$pedido['_id']} processado e confirmado (ACK) \n");
            } catch (\Throwable $e) {
                Log::error("Falha ao processar pedido na fila {$fila}", array('pedido_id' => $pedido['_id'], 'erro' => $e->getMessage()));

                // 3. PRIMEIRA FALHA: RECOLOCA NA FILA (nack true). SE JÁ FOI REENTREGUE, DESCARTA PARA NÃO FICAR EM LOOP INFINITO
                $msg->nack(!$msg->isRedelivered());
                $this->error("Falha no pedido {$pedido['_id']}: {$e->getMessage()} \n");
            }
        };

        $channel->basic_consume(
            $fila,        // nome da fila, informado pela classe filha (fila())
            '', // nome do consumidor, '' deixa o RabbitMQ gerar um
            false,     // false (irrelevante no RabbitMQ)
            false,       // false  <-- ACK MANUAL. Este é o parâmetro-chave da etapa.
            false,    // false (permite vários workers na mesma fila)
            false,       // false (espera a confirmação do RabbitMQ)
            $callback      // função chamada a cada mensagem recebida
        );

        $this->info("Aguardando mensagens na fila {$fila}. Para sair: CTRL + C \n");

        try {
            while ($channel->is_consuming()):
                $channel->wait();
            endwhile;
        } finally {
            $channel->close();
            $connection->close();
        }

        return self::SUCCESS;
    }// end of function handle()
}