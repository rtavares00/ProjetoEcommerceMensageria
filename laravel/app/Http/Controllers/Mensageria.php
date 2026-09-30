<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\View\View;

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
                $this->totalPrice += $item['preco'];
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
        $this->handleInputData($request);
        $itens = $this->getItens();
        $cliente = $this->getCustomer();
        
        $response = array(
                        'ok' => true,
                        'itens'=> $this->getItens(),
                        'cliente' => $this->getCustomer()
                    );
        
        return response()->json($response);
    }
}
