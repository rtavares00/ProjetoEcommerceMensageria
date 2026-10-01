<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EstoqueSeeder extends Seeder
{
    /**
     * Os nomes são EXATAMENTE os que a tela envia no pedido (texto do value de cada <option>
     * em resources/views/index.blade.php, antes do "|"). Qualquer diferença faria o worker
     * não encontrar o produto.
     *
     * Rodar de novo REDEFINE o saldo de todos os produtos para a quantidade inicial.
     */
    private const QUANTIDADE_INICIAL = 100;

    private const PRODUTOS = array(
        // Periféricos
        'Teclado Mecânico RGB',
        'Mouse Gamer 16000 DPI',
        'Mouse Pad XL Extra Large',
        'Headset Gamer 7.1',
        'Webcam Full HD 1080p',
        // Monitores e Vídeo
        'Monitor UltraWide 29',
        'Monitor Full HD 24',
        'Suporte Articulado para Monitor',
        // Armazenamento e Rede
        'SSD NVMe 1TB',
        'HD Externo 2TB',
        'Roteador Wi-Fi 6',
        'Hub USB-C 7 em 1',
        // Escritório
        'Cadeira Ergonômica',
        'Mesa Gamer 140cm',
        'Luminária de Mesa LED',
    );

    public function run(): void
    {
        $agora = now();

        $linhas = array_map(fn($produto) => array(
            'produto' => $produto,
            'quantidade' => self::QUANTIDADE_INICIAL,
            'created_at' => $agora,
            'updated_at' => $agora,
        ), self::PRODUTOS);

        // produto é único: se já existir, apenas atualiza o saldo
        DB::table('estoque')->upsert($linhas, array('produto'), array('quantidade', 'updated_at'));
    }
}
