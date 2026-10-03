<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

abstract class WorkerBase extends Command
{
    private ?LoggerInterface $logger = null;

    // CADA WORKER INFORMA DE QUAL FILA CONSOME
    abstract protected function fila() : string;

    // CADA WORKER FAZ O SEU TRABALHO. RETORNAR NORMALMENTE = SUCESSO; LANÇAR EXCEÇÃO = FALHA.
    // QUEM CHAMA ack() E nack() É SEMPRE A CLASSE BASE, NUNCA O processar()
    abstract protected function processar(array $pedido) : void;

    // CADA WORKER INFORMA O NOME DA SUA CONEXÃO, EXIBIDO NA UI DO RABBITMQ (ABAS Connections E Channels)
    abstract protected function nomeConexao() : string;

    // NOME DO CONSUMIDOR (consumer tag) EXIBIDO NA FILA (SEÇÃO Consumers): NOME DA CONEXÃO + PID,
    // PARA DISTINGUIR VÁRIOS WORKERS DA MESMA FILA. A CLASSE FILHA PODE SOBRESCREVER
    protected function nomeConsumidor() : string
    {
        return "{$this->nomeConexao()}-" . getmypid();
    }

    // LOG PRÓPRIO DE CADA FILA: storage/logs/workers/<fila>.log (EX.: processar_estoque.log). ASSIM O LOG DE UM WORKER
    // NÃO SE MISTURA COM O DOS OUTROS NEM COM O laravel.log. AS CLASSES FILHAS USAM $this->log()->info(...)
    // Log::build CRIA O CANAL NA HORA, SEM PRECISAR DECLARÁ-LO EM config/logging.php. O LOGGER É CRIADO UMA ÚNICA VEZ
    protected function log() : LoggerInterface
    {
        if($this->logger === null):
            $this->logger = Log::build(array(
                'driver' => 'single', // UM ARQUIVO FIXO, FÁCIL DE ACOMPANHAR COM tail -f
                'path' => storage_path("logs/workers/{$this->fila()}.log"),
                'level' => 'debug'
            ));
        endif;

        return $this->logger;
    }

    public function handle() : int
    {
        $config = config('rabbitmq'); // FAZ LEITURA DO ARQUIVO DE CONFIGURAÇÃO
        $fila = $this->fila();

        $configConexao = new AMQPConnectionConfig();
        $configConexao->setConnectionName($this->nomeConexao()); // IDENTIFICA ESTE WORKER NA UI DO RABBITMQ

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            config: $configConexao
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
                $this->log()->warning("Mensagem inválida enviada para a DLQ", array('corpo' => $msg->getBody()));
                $this->error("Mensagem inválida enviada para a DLQ");
                $msg->nack(false); // requeue = false -> VAI PARA A DLQ DESTA FILA
                return;
            endif;

            try {
                $entrega = $msg->isRedelivered() ? 'REENTREGA' : 'PRIMEIRA VEZ QUE CHEGOU';
                $this->info("Pedido {$pedido['_id']} recebido ({$entrega})\n");
                $this->log()->info("Pedido recebido ({$entrega})", array('pedido_id' => $pedido['_id']));

                // 2. O TRABALHO ESPECÍFICO DE CADA WORKER (CLASSE FILHA)
                $this->processar($pedido);

                $msg->ack(); // SÓ CONFIRMA DEPOIS DE PROCESSAR COM SUCESSO
                $this->info("Pedido {$pedido['_id']} processado e confirmado (ACK) \n");
                $this->log()->info("Pedido processado e confirmado (ACK)", array('pedido_id' => $pedido['_id']));
            } catch (\Throwable $e) {
                // 3. PRIMEIRA FALHA: RECOLOCA NA FILA (nack true). SE JÁ FOI REENTREGUE, VAI PARA A DLQ (nack false)
                $paraDlq = $msg->isRedelivered();
                $msg->nack(!$paraDlq);

                $this->log()->error($paraDlq ? "Falha na reentrega: mensagem enviada para a DLQ" : "Falha: mensagem recolocada na fila para uma nova tentativa", array('pedido_id' => $pedido['_id'], 'erro' => $e->getMessage()));
                $this->error("Falha no pedido {$pedido['_id']}: {$e->getMessage()} \n");
            }
        };

        $channel->basic_consume(
            $fila,        // nome da fila, informado pela classe filha (fila())
            $this->nomeConsumidor(), // nome do consumidor (consumer tag); '' deixaria o RabbitMQ gerar um aleatório
            false,     // false (irrelevante no RabbitMQ)
            false,       // false  <-- ACK MANUAL. Este é o parâmetro-chave da etapa.
            false,    // false (permite vários workers na mesma fila)
            false,       // false (espera a confirmação do RabbitMQ)
            $callback      // função chamada a cada mensagem recebida
        );

        $this->info("Aguardando mensagens na fila {$fila}. Para sair: CTRL + C \n");
        $this->log()->info("Worker iniciado e aguardando mensagens", array('fila' => $fila, 'consumidor' => $this->nomeConsumidor()));

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