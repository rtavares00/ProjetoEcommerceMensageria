<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Histórico das baixas de estoque, uma linha por produto de cada pedido.
     * A chave única (pedido_id, produto) garante a IDEMPOTÊNCIA: se a mesma mensagem for
     * reentregue, o INSERT falha e a baixa não é feita duas vezes.
     */
    public function up(): void
    {
        Schema::create('movimentacoes_estoque', function (Blueprint $table) {
            $table->id();
            $table->char('pedido_id', 32); // o _id gerado no checkout (bin2hex de 16 bytes)
            $table->string('produto', 120);
            $table->unsignedInteger('quantidade'); // quantidade baixada
            $table->timestamps();

            $table->unique(['pedido_id', 'produto']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimentacoes_estoque');
    }
};
