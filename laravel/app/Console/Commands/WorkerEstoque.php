<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('rabbitmq:worker-estoque')]
#[Description('Worker que consome a fila processar_estoque com ACK manual')]
class WorkerEstoque extends WorkerBase
{
    // CÓDIGOS DO MYSQL PARA CONEXÃO PERDIDA: 2006 = "MySQL server has gone away", 2013 = "Lost connection during query"
    private const ERROS_CONEXAO_PERDIDA = array(2006, 2013);

    // NULL ATÉ O PRIMEIRO USO (LAZY): O CONSTRUTOR NÃO ABRE CONEXÃO, ENTÃO OUTROS COMANDOS DO ARTISAN
    // (list, migrate...) NÃO DEPENDEM DO MYSQL ESTAR NO AR
    private ?\PDO $conn = null;

    protected function fila() : string
    {
        return "processar_estoque";
    }

    protected function nomeConexao() : string
    {
        return "worker-estoque";
    }

    /**
     * ABRE A CONEXÃO COM O MYSQL NA PRIMEIRA CHAMADA E REAPROVEITA NAS SEGUINTES.
     */
    private function dbConnection() : \PDO
    {
        if($this->conn === null):
            $db = config('database.connections.mysql');
            $this->conn = new \PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['database']};charset=utf8mb4",
                $db['username'],
                $db['password']
            );
        endif;

        return $this->conn;
    }

    private function conexaoPerdida(\PDOException $e) : bool
    {
        return in_array($e->errorInfo[1] ?? 0, self::ERROS_CONEXAO_PERDIDA, true);
    }

    protected function processar(array $pedido) : void
    {
        try {
            $this->processarPedido($pedido);
        } catch (\PDOException $e) {
            if(!$this->conexaoPerdida($e)):
                throw $e;
            endif;

            // O WORKER FICA RODANDO POR HORAS: SE O MYSQL REINICIOU OU A CONEXÃO EXPIROU, DESCARTA A CONEXÃO
            // MORTA E TENTA UMA VEZ COM UMA NOVA. É SEGURO REPETIR, POIS O PROCESSAMENTO É IDEMPOTENTE
            $this->warn("Conexão com o MySQL perdida: reconectando \n");
            $this->conn = null;
            $this->processarPedido($pedido);
        }
    }

    private function processarPedido(array $pedido) : void
    {
        // IDEMPOTÊNCIA: PEDIDO JÁ PROCESSADO (REENTREGA) NÃO BAIXA O ESTOQUE DE NOVO. RETORNAR NORMALMENTE = A BASE DÁ O ACK
        if($this->isOrderProcessed($pedido['_id'])):
            $this->warn("Pedido {$pedido['_id']} já foi processado anteriormente: ignorado \n");
            $this->log()->warning('Pedido já processado anteriormente: ignorado', array('pedido_id' => $pedido['_id']));
            return;
        endif;

        $this->baixarEstoque($pedido);

        $this->log()->info('Estoque baixado', array('pedido_id' => $pedido['_id'], 'itens' => $pedido['itens']));
    }

    /**
     * TRANSAÇÃO: A BAIXA DE TODOS OS ITENS DO PEDIDO É "TUDO OU NADA".
     */
    private function baixarEstoque(array $pedido) : void
    {
        $this->dbConnection()->beginTransaction();

        try {
            foreach($pedido['itens'] as $item):
                $this->baixarItem($pedido['_id'], $item);
            endforeach;

            $this->dbConnection()->commit();
        } catch (\Throwable $e) {
            // DESFAZ TUDO (INCLUSIVE AS MOVIMENTAÇÕES JÁ INSERIDAS) E RELANÇA PARA A BASE FAZER O nack
            $this->desfazerTransacao();
            throw $e;
        }
    }

    /**
     * SE A CONEXÃO JÁ CAIU, O ROLLBACK TAMBÉM FALHA E MASCARARIA O ERRO ORIGINAL. NESSE CASO O PRÓPRIO MYSQL
     * JÁ DESFEZ A TRANSAÇÃO, ENTÃO O ERRO DO ROLLBACK É IGNORADO.
     */
    private function desfazerTransacao() : void
    {
        try {
            if($this->dbConnection()->inTransaction()):
                $this->dbConnection()->rollBack();
            endif;
        } catch (\PDOException $e) {
            // IGNORADO DE PROPÓSITO (VER COMENTÁRIO ACIMA)
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
        $query = $this->dbConnection()->prepare(
            "insert into movimentacoes_estoque (pedido_id, produto, quantidade, created_at, updated_at)
             values (:pedido_id, :produto, :qtd, now(), now())"
        );
        $query->execute(array(':pedido_id' => $pedido_id, ':produto' => $produto, ':qtd' => $qtd));
    }

    private function debitarSaldo(string $produto, int $qtd) : void
    {
        // A CONDIÇÃO "quantidade >= :qtd_minima" É AVALIADA PELO BANCO DE FORMA ATÔMICA: NUNCA FICA NEGATIVO,
        // MESMO COM DOIS WORKERS BAIXANDO O MESMO PRODUTO AO MESMO TEMPO
        $query = $this->dbConnection()->prepare(
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
        $query = $this->dbConnection()->prepare("select 1 from movimentacoes_estoque where pedido_id = :id limit 1");
        $query->execute(array(':id' => $pedido_id));

        // fetchColumn() DEVOLVE false QUANDO NÃO HÁ LINHA: SEMPRE RETORNA bool, MESMO SEM REGISTROS
        return $query->fetchColumn() !== false;
    }
}
