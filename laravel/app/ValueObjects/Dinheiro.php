<?php

namespace App\ValueObjects;

/**
 * VALOR EM REAIS. GUARDA O VALOR EM CENTAVOS (INTEIRO) PARA NÃO ACUMULAR ERROS DE ARREDONDAMENTO DO float
 * (EX.: 3 x 0.1). IMUTÁVEL: AS OPERAÇÕES DEVOLVEM UM NOVO OBJETO.
 */
final readonly class Dinheiro
{
    private function __construct(private int $centavos)
    {
    }

    public static function deReais(float $reais) : self
    {
        return new self((int) round($reais * 100));
    }

    public function vezes(int $quantidade) : self
    {
        return new self($this->centavos * $quantidade);
    }

    // FORMATO BRASILEIRO: R$ 1.234,56
    public function formatar() : string
    {
        return 'R$ ' . number_format($this->centavos / 100, 2, ',', '.');
    }

    // FORMATO DECIMAL PARA ARQUIVOS E INTEGRAÇÕES (PONTO, SEM SEPARADOR DE MILHAR): 1234.56
    public function decimal() : string
    {
        return number_format($this->centavos / 100, 2, '.', '');
    }
}
