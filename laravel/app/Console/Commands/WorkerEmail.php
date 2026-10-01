<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

#[Signature('rabbitmq:worker-email')]
#[Description('Worker que consome a fila enviar_email e envia o e-mail de confirmação do pedido, com ACK manual')]

class WorkerEmail extends WorkerBase
{
    protected function fila() : string
    {
        return "enviar_email";
    }

    protected function nomeConexao() : string
    {
        return "worker-email";
    }

    protected function processar(array $pedido) : void
    {
        // IDEMPOTÊNCIA: E-MAIL JÁ ENVIADO (REENTREGA) NÃO É ENVIADO DE NOVO. RETORNAR NORMALMENTE = A BASE DÁ O ACK
        if($this->emailJaEnviado($pedido['_id'])):
            $this->warn("E-mail do pedido {$pedido['_id']} já foi enviado anteriormente: ignorado \n");
            return;
        endif;

        [$nome,$email] = $this->validarCliente($pedido);
        $linhasItens = $this->formatarItens($pedido['itens']);
        $total = $this->formatarValor((float) $pedido['fullprice']);

        $this->notification($nome,$email,$linhasItens,$total);

        // SÓ REGISTRA DEPOIS DE ENVIAR: SE O ENVIO FALHAR NADA É GRAVADO E A RETENTATIVA ENVIA NORMALMENTE.
        // A JANELA DE DUPLICIDADE FICA RESTRITA AO INTERVALO ENTRE O ENVIO E ESTE REGISTRO
        $this->registrarEnvio($pedido['_id'],$email);
    }

    protected function emailJaEnviado(string $pedido_id) : bool
    {
        return DB::table('emails_enviados')->where('pedido_id',$pedido_id)->exists();
    }

    protected function registrarEnvio(string $pedido_id,string $email) : void
    {
        // insertOrIgnore: SE OUTRO WORKER JÁ REGISTROU ESTE PEDIDO AO MESMO TEMPO, A CHAVE ÚNICA IMPEDE
        // O DUPLICADO E NÃO HÁ ERRO (O E-MAIL JÁ FOI ENVIADO, NÃO ADIANTA FALHAR AGORA)
        DB::table('emails_enviados')->insertOrIgnore(array(
            'pedido_id' => $pedido_id,
            'email' => $email,
            'created_at' => now(),
            'updated_at' => now()
        ));
    }

    // RETORNA [$nome, $email], JÁ VALIDADOS E SEM ESPAÇOS SOBRANDO
    protected function validarCliente(array $pedido) : array
    {
        if(!array_key_exists('customer',$pedido)):
            throw new \InvalidArgumentException("Não há dados do cliente embutido no array");
        endif;

        $customer = $pedido['customer'];
        $email = trim((string) ($customer['email'] ?? ''));
        $nome = trim((string) ($customer['nome'] ?? ''));

        // DADOS AUSENTES OU INVÁLIDOS NUNCA VÃO FUNCIONAR: LANÇA EXCEÇÃO COM UMA MENSAGEM CLARA E A BASE TRATA (nack)
        if(!filter_var($email, FILTER_VALIDATE_EMAIL)):
            throw new \InvalidArgumentException("E-mail do cliente ausente ou inválido: '{$email}'");
        endif;

        if($nome === ''):
            throw new \InvalidArgumentException("Nome do cliente ausente (e-mail: {$email})");
        endif;

        return array($nome, $email);
    }

    // UMA LINHA POR ITEM: PRODUTO, QUANTIDADE x VALOR UNITÁRIO = SUBTOTAL
    protected function formatarItens(array $itens) : string
    {
        $linhas = '';
        foreach($itens as $item):
            $qtd = (int) ($item['qtd'] ?? 0);
            $preco = (float) ($item['preco'] ?? 0);
            $linhas .= "- " . ($item['produto'] ?? '') . ": {$qtd} x " . $this->formatarValor($preco)
                . " = " . $this->formatarValor($qtd * $preco) . "\n";
        endforeach;

        return $linhas;
    }

    // FORMATO BRASILEIRO: R$ 1.234,56
    protected function formatarValor(float $valor) : string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    // APENAS ENVIA O E-MAIL: TODAS AS VARIÁVEIS JÁ CHEGAM VALIDADAS E FORMATADAS
    protected function notification(string $nome,string $email,string $linhasItens,string $total) : bool
    {
        // O ENVIO USA O MAILER E AS CREDENCIAIS (MAIL_USERNAME / MAIL_PASSWORD) DO .env, LIDAS POR config/mail.php.
        // SE O SMTP RECUSAR OU ESTIVER FORA DO AR, O Mail LANÇA EXCEÇÃO: A BASE FAZ O nack E O E-MAIL É TENTADO DE NOVO
        Mail::raw(
            "Olá, {$nome}!\n\n"
            . "Este e-mail faz parte de um EXERCÍCIO DE MENSAGERIA (estudo de RabbitMQ com Laravel). Não se trata de uma compra real.\n\n"
            . "O seu pedido de teste foi publicado no RabbitMQ e este e-mail foi enviado de forma assíncrona "
            . "pelo worker 'worker-email', que consome a fila 'enviar_email'.\n\n"
            . "Itens do pedido (produto: quantidade x valor unitário = subtotal):\n"
            . $linhasItens
            . "\nTOTAL: {$total}\n\n"
            . "Nenhum produto será entregue e nenhuma cobrança foi realizada. "
            . "Se você recebeu esta mensagem sem esperar, pode ignorá-la.",
            function($message) use ($email, $nome) {
                $message->to($email, $nome)->subject('[Exercício de Mensageria] Confirmação do pedido de teste');
            }
        );

        return true;
    }
}
