<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dos pedidos aceitos pelo checkout. É a CHAVE DE IDEMPOTÊNCIA do lado do producer:
     * o pedido_id é gerado pelo front (UUID) e a restrição UNIQUE impede que a mesma tentativa de
     * compra (duplo clique, retentativa do navegador) seja publicada duas vezes.
     */
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $table) {
            $table->id();
            $table->char('pedido_id', 36)->unique();   // UUID gerado no front
            $table->char('hash_itens', 64);            // sha256 de cliente + itens, detecta "mesmo ID com conteúdo diferente"
            $table->string('status', 20)->default('pendente'); // pendente = registrado, ainda não publicado | publicado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
