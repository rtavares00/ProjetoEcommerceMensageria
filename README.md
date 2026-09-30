# 🛒 NexusCommerce — Event-Driven Architecture com RabbitMQ & PHP

![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php)
![RabbitMQ](https://img.shields.io/badge/RabbitMQ-3.12%2B-FF6600?style=flat-square&logo=rabbitmq)
![TailwindCSS](https://img.shields.io/badge/TailwindCSS-3.0-06B6D4?style=flat-square&logo=tailwindcss)
![Docker](https://img.shields.io/badge/Docker-Supported-2496ED?style=flat-square&logo=docker)

> Projeto prático desenvolvido para estudo e demonstração de **desacoplamento de microsserviços**, **mensageria assíncrona** e **padrão Publish/Subscribe (Fanout Exchange)** utilizando **PHP** e **RabbitMQ**.

---

## 📌 Visão Geral do Problema & Solução

Em e-commerces tradicionais monolíticos, ao clicar em "Finalizar Compra", o usuário aguarda sincronamente o processamento do estoque, envio de e-mails de confirmação e emissão de Nota Fiscal. Essa abordagem gera requisições HTTP lentas, acoplamento rígido e vulnerabilidade a picos de tráfego.

O **NexusCommerce** resolve esse problema adotando uma **Arquitetura Orientada a Eventos (EDA)**:

1. **Atendimento Instantâneo:** A API de Checkout recebe o pedido, salva os dados básicos, envia o evento `pedido.realizado` ao RabbitMQ e responde ao cliente em **< 50ms** (`HTTP 202 Accepted`).
2. **Processamento Assíncrono:** Múltiplos *Workers* (microsserviços) consomem a mesma mensagem em segundo plano de forma paralela e independente.

---

## 📐 Arquitetura do Sistema

O sistema utiliza o padrão **Fanout Exchange** do protocolo AMQP. O *Producer* envia o evento diretamente para a Exchange `vendas.events`, que por sua vez replica a mensagem para todas as filas vinculadas (*bound queues*).

```mermaid
graph TD
    Client[📱 Cliente / Frontend HTML] -->|HTTP POST /checkout.php| API[🚀 API Checkout Producer]
    
    subgraph RabbitMQ Broker
        API -->|1. Publica Evento| Ex[⚡ Exchange: vendas.events <br/>type: fanout]
        
        Ex -->|2a. Broadcast| Q1[📥 Fila: processar_estoque]
        Ex -->|2b. Broadcast| Q2[📥 Fila: enviar_email]
        Ex -->|2c. Broadcast| Q3[📥 Fila: gerar_nota]
    end

    subgraph Workers / Consumers CLI
        Q1 -->|3a. Consome| W1[📦 Worker Estoque]
        Q2 -->|3b. Consome| W2[✉️ Worker E-mail]
        Q3 -->|3c. Consome| W3[📄 Worker Nota Fiscal]
    end
