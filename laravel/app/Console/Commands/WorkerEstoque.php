<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Log;

#[Signature('rabbitmq:worker-estoque')]
#[Description('Worker que consome a fila processar_estoque com ACK manual')]
class WorkerEstoque extends WorkerBase
{
    private \PDO $conn;

    public function __construct()
    {
        parent::__construct();

        $db = config('database.connections.mysql');
        $this->conn = new \PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4",
            $db['username'],
            $db['password']
        );
    }

    protected function fila() : string
    {
        return "processar_estoque";
    }

    protected function nomeConexao() : string
    {
        return "worker-estoque";
    }

    protected function processar(array $pedido) : void
    {
        // IDEMPOTÊNCIA: PEDIDO JÁ PROCESSADO (REENTREGA) NÃO BAIXA O ESTOQUE DE NOVO. RETORNAR NORMALMENTE = A BASE DÁ O ACK
        if($this->isOrderProcessed($pedido['_id'])):
            $this->warn("Pedido {$pedido['_id']} já foi processado anteriormente: ignorado \n");
            return;
        endif;

        $this->baixarEstoque($pedido);

        Log::info('Estoque baixado', array('pedido_id' => $pedido['_id'], 'itens' => $pedido['itens']));
    }

    /**
     * TRANSAÇÃO: A BAIXA DE TODOS OS ITENS DO PEDIDO É "TUDO OU NADA".
     */
    private function baixarEstoque(array $pedido) : void
    {
        $this->conn->beginTransaction();

        try {
            foreach($pedido['itens'] as $item):
                $this->baixarItem($pedido['_id'], $item);
            endforeach;

            $this->conn->commit();
        } catch (\Throwable $e) {
            // DESFAZ TUDO (INCLUSIVE AS MOVIMENTAÇÕES JÁ INSERIDAS) E RELANÇA PARA A BASE FAZER O nack
            if($this->conn->inTransaction()):
                $this->conn->rollBack();
            endif;
            throw $e;
        }
    }

    /**
     * VALIDA UM ITEM, REGISTRA A MOVIMENTAÇÃO E DEBITA O SALDO.
     */
    private function baixarItem(string $pedido_id, array $item) : void
    {
        $produto = $item['produto'] ?? null;
        $qtd = (int) ($item['qtd'] ?? 0);

        if(empty($produto) || $qtd < 1):
            throw new \InvalidArgumentException("Item inválido no pedido {$pedido_id}");
        endif;

        $this->registrarMovimentacao($pedido_id, $produto, $qtd);
        $this->debitarSaldo($produto, $qtd);
    }

    private function registrarMovimentacao(string $pedido_id, string $produto, int $qtd) : void
    {
        $query = $this->conn->prepare(
            "insert into movimentacoes_estoque (pedido_id, produto, quantidade, created_at, updated_at)
             values (:pedido_id, :produto, :qtd, now(), now())"
        );
        $query->execute(array(':pedido_id' => $pedido_id, ':produto' => $produto, ':qtd' => $qtd));
    }

    private function debitarSaldo(string $produto, int $qtd) : void
    {
        // A CONDIÇÃO "quantidade >= :qtd_minima" É AVALIADA PELO BANCO DE FORMA ATÔMICA: NUNCA FICA NEGATIVO,
        // MESMO COM DOIS WORKERS BAIXANDO O MESMO PRODUTO AO MESMO TEMPO
        $query = $this->conn->prepare(
            "update estoque set quantidade = quantidade - :qtd_baixa, updated_at = now()
             where produto = :produto and quantidade >= :qtd_minima"
        );
        $query->execute(array(':qtd_baixa' => $qtd, ':produto' => $produto, ':qtd_minima' => $qtd));

        // NENHUMA LINHA ALTERADA = PRODUTO INEXISTENTE OU SALDO INSUFICIENTE
        if($query->rowCount() === 0):
            throw new \RuntimeException("Estoque insuficiente ou produto inexistente: {$produto} (solicitado: {$qtd})");
        endif;
    }

    protected function isOrderProcessed(string $pedido_id) : bool
    {
        // "limit 1" PARA NO PRIMEIRO REGISTRO ENCONTRADO, EM VEZ DE CONTAR TODOS (basta saber se existe)
        $query = $this->conn->prepare("select 1 from movimentacoes_estoque where pedido_id = :id limit 1");
        $query->execute(array(':id' => $pedido_id));

        // fetchColumn() DEVOLVE false QUANDO NÃO HÁ LINHA: SEMPRE RETORNA bool, MESMO SEM REGISTROS
        return $query->fetchColumn() !== false;
    }
}
