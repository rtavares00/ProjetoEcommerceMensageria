<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\PedidoProducer;

class Mensageria extends Controller
{
    private $itens ;
    private $customer ;
    private $totalPrice ;
    private $totalQtd ;

    public function index() : View
    {
        return view("index");
    }

    public function welcomePage() : View
    {
        return view("welcome");
    }

    private function handleInputData($request) : void
    {
        $this->handleItensData($request);
        $this->handleCustomerData($request);
    }

    private function handleItensData($request) : void
    {
        $this->itens = array();
        $this->totalPrice = 0;
        $this->totalQtd = 0;
        if(!empty($request->itens)):
            $this->itens = $request->itens;
            foreach($request->itens as $item):
                $this->totalPrice += $item['preco'] * $item['qtd'];
                $this->totalQtd += $item['qtd'];
            endforeach;
        endif;
    }

    private function handleCustomerData($request) : void
    {
        $this->customer = array();
        if(!empty($request->cliente)):
            $this->customer = $request->cliente;
        endif;
    }

    private function getItens() : array
    {
        return $this->itens;
    }

    private function getCustomer() : array
    {
        return $this->customer;
    }

    public function checkout(Request $request) : JsonResponse
    {
        // O pedido_id É A CHAVE DE IDEMPOTÊNCIA: UM UUID GERADO PELO FRONT PARA CADA TENTATIVA DE COMPRA
        $pedidoId = (string) $request->input('pedido_id');
        if(!Str::isUuid($pedidoId)):
            return $this->respostaErro('Identificador do pedido (pedido_id) ausente ou inválido.', 422);
        endif;

        $this->handleInputData($request);

        $pedido = array(
            '_id' => $pedidoId,
            'customer' => $this->getCustomer(),
            'itens' => $this->getItens(),
            'fullprice' => $this->totalPrice
        );
        $hash = $this->gerarHash($pedido);

        // null = PEDIDO NOVO; OBJETO = ESSE pedido_id JÁ FOI REGISTRADO ANTES (REPETIÇÃO)
        $registro = $this->registrarPedido($pedidoId, $hash);

        if($registro !== null && $registro->hash_itens !== $hash):
            return $this->respostaErro('Este pedido_id já foi usado com outro conteúdo.', 409);
        endif;

        if($registro !== null && $registro->status === 'publicado'):
            return $this->respostaAceita($pedidoId, true); // REPETIÇÃO: NÃO PUBLICA DE NOVO
        endif;

        // PEDIDO NOVO OU REPETIÇÃO DE UM QUE FICOU 'pendente' (A PUBLICAÇÃO ANTERIOR FALHOU).
        // PUBLICAR DE NOVO É SEGURO: O WORKER DE ESTOQUE É IDEMPOTENTE
        try{
            $producer = new PedidoProducer(); // ABRE A CONEXÃO COM O RABBITMQ
            $producer->publicar( $pedido );
        }catch(\Exception $e){
            Log::error('Falha ao publicar pedido no RabbitMQ', array(
                'pedido_id' => $pedidoId,
                'erro' => $e->getMessage()
            ));

            // O REGISTRO FICA 'pendente': A RETENTATIVA COM O MESMO pedido_id PUBLICA NOVAMENTE
            return $this->respostaErro('Serviço de mensageria indisponível. Tente novamente em instantes.', 503);
        }

        unset($producer);
        DB::table('pedidos')->where('pedido_id', $pedidoId)->update(array('status' => 'publicado', 'updated_at' => now()));

        return $this->respostaAceita($pedidoId, false);
    }

    /**
     * SHA-256 DO CONTEÚDO (CLIENTE + ITENS): DETECTA O MESMO pedido_id ENVIADO COM CONTEÚDO DIFERENTE.
     */
    private function gerarHash(array $pedido) : string
    {
        return hash('sha256', json_encode(array($pedido['customer'], $pedido['itens'])));
    }

    /**
     * TENTA INSERIR O pedido_id. A RESTRIÇÃO UNIQUE DO BANCO É QUEM GARANTE A IDEMPOTÊNCIA,
     * MESMO COM DUAS REQUISIÇÕES CHEGANDO AO MESMO TEMPO.
     * RETORNA null SE O PEDIDO É NOVO, OU O REGISTRO EXISTENTE SE FOR REPETIÇÃO.
     */
    private function registrarPedido(string $pedidoId, string $hash) : ?object
    {
        try{
            DB::table('pedidos')->insert(array(
                'pedido_id' => $pedidoId,
                'hash_itens' => $hash,
                'status' => 'pendente',
                'created_at' => now(),
                'updated_at' => now()
            ));
            return null;
        }catch(UniqueConstraintViolationException $e){
            return DB::table('pedidos')->where('pedido_id', $pedidoId)->first();
        }
    }

    private function respostaAceita(string $pedidoId, bool $duplicado) : JsonResponse
    {
        return response()->json(
            array(
                'ok' => true,
                'pedido_id' => $pedidoId,
                'duplicado' => $duplicado,
                'itens'=> $this->getItens(),
                'cliente' => $this->getCustomer()
            ),
            202
        );
    }

    private function respostaErro(string $mensagem, int $status) : JsonResponse
    {
        return response()->json(array('ok' => false, 'mensagem' => $mensagem), $status);
    }
}
