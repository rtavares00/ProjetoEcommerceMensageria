<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro dos e-mails de confirmação já enviados, um por pedido.
     * É o controle de idempotência do worker de e-mail: se a mensagem for reentregue pelo RabbitMQ,
     * o pedido_id já estará aqui e o e-mail não é enviado de novo. A chave UNIQUE garante isso
     * mesmo com dois workers processando o mesmo pedido ao mesmo tempo.
     */
    public function up(): void
    {
        Schema::create('emails_enviados', function (Blueprint $table) {
            $table->id();
            $table->char('pedido_id', 36)->unique(); // o _id do pedido (UUID gerado no front)
            $table->string('email', 255);            // destinatário, para consulta/auditoria
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails_enviados');
    }
};
