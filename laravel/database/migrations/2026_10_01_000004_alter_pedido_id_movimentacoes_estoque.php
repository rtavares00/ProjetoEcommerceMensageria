<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O pedido_id deixou de ser o hexadecimal de 32 caracteres gerado no servidor e passou a ser
     * o UUID gerado no front (36 caracteres). Os registros existentes continuam válidos.
     */
    public function up(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->char('pedido_id', 36)->change();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacoes_estoque', function (Blueprint $table) {
            $table->char('pedido_id', 32)->change();
        });
    }
};
