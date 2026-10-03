<?php

namespace App\Console\Commands;

use App\ValueObjects\Dinheiro;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

#[Signature('rabbitmq:worker-nota')]
#[Description('Worker que consome a fila gerar_nota e gera a nota fiscal (fictícia, em XML) do pedido em storage/app/notas, com ACK manual')]

class WorkerNota extends WorkerBase
{
    protected function fila() : string
    {
        return "gerar_nota";
    }

    protected function nomeConexao() : string
    {
        return "worker-nota";
    }

    protected function processar(array $pedido) : void
    {
        $arquivo = $this->caminhoNota($pedido['_id']);

        // IDEMPOTÊNCIA: O NOME DO ARQUIVO VEM DO pedido_id, ENTÃO A NOTA JÁ GERADA INDICA UMA REENTREGA.
        // RETORNAR NORMALMENTE = A BASE DÁ O ACK
        if(file_exists($arquivo)):
            $this->warn("Nota do pedido {$pedido['_id']} já foi gerada anteriormente: ignorada \n");
            $this->log()->warning('Nota já gerada anteriormente: ignorada', array('pedido_id' => $pedido['_id']));
            return;
        endif;

        $nome = $this->validarNome($pedido);
        $conteudo = $this->montarNota($pedido,$nome);

        $this->gravarNota($arquivo,$conteudo);
        $this->info("Nota gerada: {$arquivo} \n");
        $this->log()->info('Nota gerada', array('pedido_id' => $pedido['_id'], 'arquivo' => basename($arquivo)));
    }

    protected function caminhoNota(string $pedido_id) : string
    {
        // O pedido_id VIRA NOME DE ARQUIVO: SÓ LETRAS, NÚMEROS, "-" E "_" (IMPEDE CAMINHOS COMO ../../x)
        if(!preg_match('/^[A-Za-z0-9_-]+$/',$pedido_id)):
            throw new \InvalidArgumentException("Identificador de pedido inválido para nome de arquivo: '{$pedido_id}'");
        endif;

        return storage_path("app/notas/nota_{$pedido_id}.xml");
    }

    // O NOME DO CLIENTE É OBRIGATÓRIO NA NOTA. AUSENTE = EXCEÇÃO COM MENSAGEM CLARA E A BASE TRATA (nack)
    protected function validarNome(array $pedido) : string
    {
        $nome = trim((string) ($pedido['customer']['nome'] ?? ''));

        if($nome === ''):
            throw new \InvalidArgumentException("Nome do cliente ausente no pedido {$pedido['_id']}");
        endif;

        return $nome;
    }

    // ADICIONA <$tag>$valor</$tag> AO ELEMENTO PAI. O VALOR VAI COMO NÓ DE TEXTO, ENTÃO O PHP ESCAPA
    // CARACTERES ESPECIAIS (& < >) SOZINHO E O XML NUNCA FICA INVÁLIDO
    protected function adicionarElemento(\DOMDocument $xml,\DOMElement $pai,string $tag,string $valor) : \DOMElement
    {
        $elemento = $xml->createElement($tag);
        $elemento->appendChild($xml->createTextNode($valor));
        $pai->appendChild($elemento);

        return $elemento;
    }

    protected function montarNota(array $pedido,string $nome) : string
    {
        $email = trim((string) ($pedido['customer']['email'] ?? ''));

        $xml = new \DOMDocument('1.0','UTF-8');
        $xml->formatOutput = true;

        $nota = $xml->createElement('notaFiscal');
        $xml->appendChild($nota);

        $this->adicionarElemento($xml,$nota,'aviso','Exercício de mensageria: documento fictício, sem valor fiscal.');
        $this->adicionarElemento($xml,$nota,'pedido',$pedido['_id']);
        $this->adicionarElemento($xml,$nota,'emissao',now()->format('c'));

        $cliente = $xml->createElement('cliente');
        $nota->appendChild($cliente);
        $this->adicionarElemento($xml,$cliente,'nome',$nome);
        if($email !== ''):
            $this->adicionarElemento($xml,$cliente,'email',$email);
        endif;

        $itens = $xml->createElement('itens');
        $nota->appendChild($itens);
        foreach($pedido['itens'] as $item):
            $produto = trim((string) ($item['produto'] ?? ''));
            $qtd = (int) ($item['qtd'] ?? 0);
            $reais = (float) ($item['preco'] ?? 0);

            if($produto === '' || $qtd < 1 || $reais < 0):
                throw new \InvalidArgumentException("Item inválido no pedido {$pedido['_id']}");
            endif;

            $preco = Dinheiro::deReais($reais);

            $elementoItem = $xml->createElement('item');
            $itens->appendChild($elementoItem);
            $this->adicionarElemento($xml,$elementoItem,'produto',$produto);
            $this->adicionarElemento($xml,$elementoItem,'quantidade',(string) $qtd);
            $this->adicionarElemento($xml,$elementoItem,'valorUnitario',$preco->decimal());
            $this->adicionarElemento($xml,$elementoItem,'subtotal',$preco->vezes($qtd)->decimal());
        endforeach;

        $total = $this->adicionarElemento($xml,$nota,'total',Dinheiro::deReais((float) $pedido['fullprice'])->decimal());
        $total->setAttribute('moeda','BRL');

        return $xml->saveXML();
    }

    // GRAVA EM UM ARQUIVO TEMPORÁRIO E RENOMEIA: O rename É ATÔMICO, ENTÃO NUNCA EXISTE UMA NOTA PELA METADE
    // (SE O WORKER CAIR NO MEIO DA GRAVAÇÃO, A REENTREGA NÃO ENCONTRA UM ARQUIVO INCOMPLETO E GERA A NOTA DE NOVO)
    protected function gravarNota(string $arquivo,string $conteudo) : void
    {
        $diretorio = dirname($arquivo);
        if(!is_dir($diretorio)):
            mkdir($diretorio,0775,true);
        endif;

        $temporario = $arquivo . '.tmp';

        if(file_put_contents($temporario,$conteudo) === false):
            throw new \RuntimeException("Não foi possível gravar a nota em {$temporario}");
        endif;

        rename($temporario,$arquivo);
    }
}
