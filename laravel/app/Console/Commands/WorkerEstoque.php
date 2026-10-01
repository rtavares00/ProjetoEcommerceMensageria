<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

#[Signature('rabbitmq:worker-estoque')]
#[Description('Worker que consome a fila processar_estoque com ACK manual')]
class WorkerEstoque extends Command
{
    /**
     * Execute the console command.
     */
    public function handle() : int
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

        $channel = $connection->channel(); // ESTABELECE CANAL DE COMUNICAÇÃO
        $this->info("Estabelecido o Canal de Comunicação \n");

        $channel->basic_qos(
            0, //prefetch_size	0	Sem limite em bytes (quase sempre 0).
            1,  //prefetch_count	1	O worker recebe 1 mensagem por vez; só recebe a próxima depois do ack.
            false //a_global	false	O limite vale por consumidor, não por canal inteiro.
        );

        // O CALLBACK PRECISA EXISTIR ANTES DO basic_consume, POIS ELE É PASSADO COMO PARÂMETRO
        $callback = function(AMQPMessage $msg)
        {
            $pedido = json_decode($msg->getBody(), true);

            // 1. VALIDAR O QUE CHEGOU: MENSAGEM INVÁLIDA NUNCA VAI FUNCIONAR, ENTÃO NÃO ADIANTA RECOLOCAR NA FILA
            if(!is_array($pedido) || empty($pedido['_id']) || empty($pedido['itens'])):
                Log::warning('Mensagem inválida descartada na fila processar_estoque', array('corpo' => $msg->getBody()));
                $this->error("Mensagem inválida descartada");
                $msg->nack(false); // requeue = false -> DESCARTA (AINDA NÃO EXISTE DLQ)
                return;
            endif;

            /*
            // ARQUIVO-SINALIZADOR DA FALHA SIMULADA: SÓ É CRIADO NA PRIMEIRA ENTREGA, ENTÃO A REENTREGA NÃO FALHA
            $arquivoTeste = storage_path("logs/falha_simulada_{$pedido['_id']}.log");
            if(!$msg->isRedelivered()):
                file_put_contents($arquivoTeste, "Falha simulada do pedido {$pedido['_id']} em " . date('Y-m-d H:i:s') . PHP_EOL);
            endif;
            */
            try {
                /*    
                if(file_exists($arquivoTeste)):
                    throw new \RuntimeException("FALHA SIMULADA");
                endif;
                */
                $this->info("Pedido {$pedido['_id']} recebido" . ($msg->isRedelivered() ? ' (REENTREGA)' : '(PRIMEIRA VEZ QUE CHEGOU)') . "\n");

                // 2. PROCESSAR O ESTOQUE (VERSÃO MÍNIMA: LOG + SLEEP SIMULANDO O TRABALHO)
                Log::info('Processando estoque do pedido', array('pedido_id' => $pedido['_id'], 'itens' => $pedido['itens']));
                sleep(2);

                $msg->ack(); // SÓ CONFIRMA DEPOIS DE PROCESSAR COM SUCESSO
                $this->info("Pedido {$pedido['_id']} processado e confirmado (ACK) \n");
            } catch (\Throwable $e) {
                /*
                // REMOVE O ARQUIVO-SINALIZADOR: A REENTREGA ENCONTRA O AMBIENTE "CONSERTADO" E DEVE PROCESSAR COM SUCESSO
                if(file_exists($arquivoTeste)):
                    unlink($arquivoTeste);
                endif;
                */
                Log::error('Falha ao processar estoque', array('pedido_id' => $pedido['_id'], 'erro' => $e->getMessage()));

                // 3. PRIMEIRA FALHA: RECOLOCA NA FILA (nack true). SE JÁ FOI REENTREGUE, DESCARTA PARA NÃO FICAR EM LOOP INFINITO
                $msg->nack(!$msg->isRedelivered());
                $this->error("Falha no pedido {$pedido['_id']}: {$e->getMessage()} \n");
                //sleep(10);
            }
        };

        $channel->basic_consume(
            'processar_estoque',        // nome da fila: 'processar_estoque'
            '', // nome do consumidor, '' deixa o RabbitMQ gerar um
            false,     // false (irrelevante no RabbitMQ)
            false,       // false  <-- ACK MANUAL. Este é o parâmetro-chave da etapa.
            false,    // false (permite vários workers na mesma fila)
            false,       // false (espera a confirmação do RabbitMQ)
            $callback      // função chamada a cada mensagem recebida
        );

        $this->info("Aguardando mensagens na fila processar_estoque. Para sair: CTRL + C \n");

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
