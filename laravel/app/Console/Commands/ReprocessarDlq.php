<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;

#[Signature('rabbitmq:reprocessar-dlq {fila : Fila de origem (processar_estoque, enviar_email ou gerar_nota)} {--listar : Apenas lista as mensagens da DLQ, sem mover} {--pedido= : Reprocessa só o pedido com este _id} {--limite= : Quantidade máxima de mensagens a reprocessar}')]
#[Description('Devolve as mensagens de uma DLQ para a fila de origem, para serem processadas de novo (use --listar para apenas ver)')]
class ReprocessarDlq extends Command
{
    public function handle() : int
    {
        $config = config('rabbitmq');
        $fila = $this->argument('fila');

        if(!in_array($fila, $config['queues'], true)):
            $this->error("Fila inválida: '{$fila}'. Use uma de: " . implode(', ', $config['queues']));
            return self::FAILURE;
        endif;

        $dlq = $fila . $config['dlq_sufixo'];

        $configConexao = new AMQPConnectionConfig();
        $configConexao->setConnectionName('reprocessar-dlq'); // IDENTIFICA ESTE COMANDO NA UI DO RABBITMQ

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
            config: $configConexao
        );
        $channel = $connection->channel();

        try {
            // passive = true: SÓ CONSULTA. DEVOLVE [nome, quantidade de mensagens, quantidade de consumidores]
            [, $total] = $channel->queue_declare($dlq, true);
        } catch (AMQPProtocolChannelException $e) {
            $this->error("A DLQ '{$dlq}' não existe. Rode: php artisan rabbitmq:setup");
            $connection->close();
            return self::FAILURE;
        }

        if($total === 0):
            $this->info("A DLQ '{$dlq}' está vazia. Nada a fazer.");
            $channel->close();
            $connection->close();
            return self::SUCCESS;
        endif;

        $listar = (bool) $this->option('listar');
        $filtro = $this->option('pedido');
        $limite = $this->option('limite') !== null ? (int) $this->option('limite') : $total;

        // PUBLISHER CONFIRMS: O RABBITMQ CONFIRMA QUE GRAVOU A MENSAGEM NA FILA DE ORIGEM ANTES DE ELA SAIR DA DLQ
        if(!$listar):
            $channel->confirm_select();
        endif;

        $this->info(($listar ? "Mensagens" : "Reprocessando mensagens") . " da DLQ '{$dlq}' ({$total} no total):\n");

        $lidas = 0;
        $movidas = 0;
        $devolver = array();

        // LÊ NO MÁXIMO $total MENSAGENS. UMA MENSAGEM QUE NÃO SERÁ MOVIDA É DEVOLVIDA SÓ NO FINAL:
        // SE FOSSE DEVOLVIDA NA HORA, O basic_get A ENTREGARIA DE NOVO E O LAÇO NUNCA TERMINARIA
        while($lidas < $total && $movidas < $limite):
            $msg = $channel->basic_get($dlq); // no_ack = false (PADRÃO): A MENSAGEM FICA "EM VOO" ATÉ O ack OU nack
            if($msg === null):
                break;
            endif;
            $lidas++;

            $pedido = json_decode($msg->getBody(), true);
            $id = is_array($pedido) ? ($pedido['_id'] ?? '(sem _id)') : '(corpo inválido)';
            $correspondente = ($filtro === null || $id === $filtro);

            if($correspondente):
                $this->line($this->descrever($msg,$id));
            endif;

            if($listar || !$correspondente):
                $devolver[] = $msg;
                continue;
            endif;

            $this->republicar($channel,$fila,$msg);
            $msg->ack(); // SÓ APAGA DA DLQ DEPOIS DE A FILA DE ORIGEM TER CONFIRMADO O RECEBIMENTO
            $movidas++;
        endwhile;

        foreach($devolver as $msg):
            $msg->nack(true); // requeue = true: VOLTA PARA A DLQ, INTACTA
        endforeach;

        $this->newLine();
        if($listar):
            $this->info("Nenhuma mensagem foi movida (modo --listar).");
        else:
            $this->info("{$movidas} mensagem(ns) devolvida(s) à fila '{$fila}'.");
            if($movidas > 0):
                $this->warn("Se a causa da falha não foi corrigida, a mensagem falhará de novo e voltará para a DLQ.");
            endif;
        endif;

        $channel->close();
        $connection->close();

        return self::SUCCESS;
    }

    // UMA LINHA POR MENSAGEM: PEDIDO, FILA DE ORIGEM, MOTIVO E QUANTAS VEZES MORREU (CABEÇALHO x-death DO RABBITMQ)
    protected function descrever(AMQPMessage $msg,string $id) : string
    {
        $headers = $msg->has('application_headers') ? $msg->get('application_headers')->getNativeData() : array();
        $morte = $headers['x-death'][0] ?? array();

        return "- pedido {$id} | origem: " . ($morte['queue'] ?? '?')
            . " | motivo: " . ($morte['reason'] ?? '?')
            . " | vezes: " . ($morte['count'] ?? '?');
    }

    protected function republicar($channel,string $fila,AMQPMessage $msg) : void
    {
        // CÓPIA DAS PROPRIEDADES (delivery_mode 2, content_type...) SEM OS CABEÇALHOS: O x-death ANTIGO NÃO VIAJA
        $propriedades = $msg->get_properties();
        unset($propriedades['application_headers']);

        // exchange '' = EXCHANGE PADRÃO: ENTREGA DIRETO NA FILA CUJO NOME É A routing key.
        // NÃO USAR A vendas.events (fanout): A MENSAGEM IRIA PARA AS TRÊS FILAS, E NÃO SÓ PARA A QUE FALHOU
        $channel->basic_publish(new AMQPMessage($msg->getBody(),$propriedades),'',$fila);
        $channel->wait_for_pending_acks(); // ESPERA A CONFIRMAÇÃO DO RABBITMQ
    }
}
