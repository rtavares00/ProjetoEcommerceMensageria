<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo atual de cada produto.
     * O pedido chega com o NOME do produto (a tela não envia um id), então o nome é a chave de busca.
     */
    public function up(): void
    {
        Schema::create('estoque', function (Blueprint $table) {
            $table->id();
            $table->string('produto', 120)->unique();
            $table->unsignedInteger('quantidade')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estoque');
    }
};
