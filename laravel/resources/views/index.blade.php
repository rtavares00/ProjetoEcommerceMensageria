<!DOCTYPE html>
<html lang="pt-BR" class="h-full bg-slate-900 text-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NexusCommerce - Dev Sandbox RabbitMQ</title>
    <!-- Tailwind CSS CDN para estilização rápida -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="h-full flex flex-col font-sans antialiased">

    <!-- Header / Navbar -->
    <header class="border-b border-slate-800 bg-slate-950 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="bg-indigo-600 p-2 rounded-lg">
                <i data-lucide="shopping-cart" class="w-6 h-6 text-white"></i>
            </div>
            <div>
                <h1 class="text-lg font-bold text-white tracking-wide">NexusCommerce</h1>
                <p class="text-xs text-slate-400">Environment de Teste para Async Workers (RabbitMQ)</p>
            </div>
        </div>
        <div class="flex items-center gap-2 bg-slate-900 border border-slate-800 px-3 py-1.5 rounded-full text-xs text-slate-400">
            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>PHP API / Consumer Ready</span>
        </div>
    </header>

    <!-- Main Content Grid -->
    <main class="flex-1 p-6 grid grid-cols-1 lg:grid-cols-12 gap-6 overflow-hidden">
        
        <!-- Coluna Esquerda: Form de Simulador de Checkout (4 colunas) -->
        <section class="lg:col-span-4 bg-slate-950 border border-slate-800 rounded-xl p-5 flex flex-col justify-between shadow-xl">
            <div>
                <div class="flex items-center gap-2 mb-4 pb-3 border-b border-slate-800">
                    <i data-lucide="send" class="w-5 h-5 text-indigo-400"></i>
                    <h2 class="font-semibold text-slate-200">Disparar Evento (Producer)</h2>
                </div>

                <form id="checkoutForm" class="space-y-4">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Nome do Cliente</label>
                        <input type="text" id="cliente_nome" value="Dev PHP" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">E-mail do Cliente</label>
                        <input type="email" id="cliente_email" value="dev@exemplo.com" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Item Selecionado</label>
                        <select id="produto" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
                            <option value="Teclado Mecânico RGB|350.00">Teclado Mecânico RGB - R$ 350,00</option>
                            <option value="Mouse Pad XL Extra Large|80.00">Mouse Pad XL Extra Large - R$ 80,00</option>
                            <option value="Monitor UltraWide 29|1200.00">Monitor UltraWide 29" - R$ 1.200,00</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Quantidade</label>
                        <input type="number" id="quantidade" value="1" min="1" max="10" required
                            class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-sm text-slate-200 focus:outline-none focus:border-indigo-500 transition">
                    </div>

                    <button type="submit" id="btnSubmit"
                        class="w-full mt-2 bg-indigo-600 hover:bg-indigo-500 active:bg-indigo-700 text-white font-medium py-2.5 px-4 rounded-lg flex items-center justify-center gap-2 text-sm transition shadow-lg shadow-indigo-600/20">
                        <i data-lucide="zap" class="w-4 h-4"></i>
                        <span>Finalizar Compra (POST /checkout)</span>
                    </button>
                </form>
            </div>

            <!-- Card Informativo -->
            <div class="mt-6 p-3 bg-indigo-950/40 border border-indigo-900/50 rounded-lg text-xs text-indigo-300">
                <p class="font-semibold mb-1">ℹ️ Comportamento Esperado:</p>
                <p>O envio dispara uma requisição para seu script PHP (`checkout.php`). O backend publica a mensagem no Exchange <code class="bg-indigo-900/60 px-1 rounded">vendas.events</code> e responde em &lt; 50ms.</p>
            </div>
        </section>

        <!-- Coluna Direita: Dashboard de Monitoramento / Consumidores (8 colunas) -->
        <section class="lg:col-span-8 flex flex-col gap-6 overflow-hidden">
            
            <!-- Cards de Status dos Workers -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                
                <!-- Worker 1 -->
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
                    <div class="p-2.5 bg-blue-500/10 text-blue-400 rounded-lg">
                        <i data-lucide="boxes" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Worker Estoque</p>
                        <p class="text-sm font-semibold text-slate-200">Fila: <span class="text-blue-400">processar_estoque</span></p>
                    </div>
                </div>

                <!-- Worker 2 -->
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
                    <div class="p-2.5 bg-emerald-500/10 text-emerald-400 rounded-lg">
                        <i data-lucide="mail" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Worker E-mail</p>
                        <p class="text-sm font-semibold text-slate-200">Fila: <span class="text-emerald-400">enviar_email</span></p>
                    </div>
                </div>

                <!-- Worker 3 -->
                <div class="bg-slate-950 border border-slate-800 p-4 rounded-xl flex items-center gap-3">
                    <div class="p-2.5 bg-amber-500/10 text-amber-400 rounded-lg">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <p class="text-xs text-slate-400">Worker Nota Fiscal</p>
                        <p class="text-sm font-semibold text-slate-200">Fila: <span class="text-amber-400">gerar_nota</span></p>
                    </div>
                </div>

            </div>

            <!-- Console de Logs da Aplicação -->
            <div class="flex-1 bg-slate-950 border border-slate-800 rounded-xl p-4 flex flex-col overflow-hidden shadow-xl">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-800">
                    <div class="flex items-center gap-2">
                        <i data-lucide="terminal" class="w-4 h-4 text-emerald-400"></i>
                        <h3 class="text-sm font-semibold text-slate-300">Console de Atividades (API Frontend Log)</h3>
                    </div>
                    <button onclick="clearConsole()" class="text-xs text-slate-500 hover:text-slate-300 transition">
                        Limpar Logs
                    </button>
                </div>

                <!-- Terminal / Log Stream -->
                <div id="consoleLog" class="flex-1 bg-slate-900 rounded-lg p-3 font-mono text-xs overflow-y-auto space-y-2 border border-slate-800/80">
                    <div class="text-slate-500">[SYSTEM] Painel pronto. Faça um pedido para testar o envio de mensagens ao backend...</div>
                </div>
            </div>

        </section>

    </main>

    <!-- JavaScript Simples para Integração com seu PHP -->
    <script>
        // Inicializa os ícones do Lucide
        lucide.createIcons();

        const form = document.getElementById('checkoutForm');
        const consoleLog = document.getElementById('consoleLog');

        function appendLog(message, type = 'info') {
            const time = new Date().toLocaleTimeString();
            const logItem = document.createElement('div');
            
            let colorClass = 'text-slate-300';
            if (type === 'success') colorClass = 'text-emerald-400';
            if (type === 'error') colorClass = 'text-rose-400';
            if (type === 'system') colorClass = 'text-indigo-400';

            logItem.className = `${colorClass} leading-relaxed`;
            logItem.innerHTML = `<span class="text-slate-600">[${time}]</span> ${message}`;
            
            consoleLog.appendChild(logItem);
            consoleLog.scrollTop = consoleLog.scrollHeight;
        }

        function clearConsole() {
            consoleLog.innerHTML = '<div class="text-slate-500">[SYSTEM] Console limpo.</div>';
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const [prodNome, prodPreco] = document.getElementById('produto').value.split('|');
            const qtd = parseInt(document.getElementById('quantidade').value);
            const total = (parseFloat(prodPreco) * qtd).toFixed(2);

            const payload = {
                cliente: {
                    nome: document.getElementById('cliente_nome').value,
                    email: document.getElementById('cliente_email').value,
                },
                itens: [
                    { produto: prodNome, qtd: qtd, preco: parseFloat(prodPreco) }
                ],
                valor_total: parseFloat(total)
            };

            appendLog(`🚀 Enviando requisição POST para seu backend PHP...`, 'system');

            try {
                // Altere o caminho '/checkout.php' caso o seu script de backend tenha outro nome
                const response = await fetch('checkout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                if (response.ok) {
                    const resData = await response.json().catch(() => ({ status: 'HTTP ' + response.status }));
                    appendLog(`✅ API respondeu em tempo recorde! Mensagem publicada no RabbitMQ.`, 'success');
                    appendLog(`📦 Resposta da API: <code class="text-slate-400">${JSON.stringify(resData)}</code>`, 'info');
                } else {
                    appendLog(`❌ Erro no envio. O script checkout.php respondeu status ${response.status}`, 'error');
                }
            } catch (err) {
                // Tratamento simulado caso você abra a tela sem o servidor PHP estar rodando ainda
                appendLog(`⚠️ Não foi possível alcançar o arquivo 'checkout.php'. Certifique-se de estar rodando um servidor PHP (ex: php -S localhost:8000).`, 'error');
            }
        });
    </script>
</body>
</html>