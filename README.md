# 🤖 CleanWalletAI - Finance Assistant Bot

CleanWalletAI é um assistente financeiro inteligente para Telegram, construído com **Laravel 11**, **MongoDB** e **IA (Langflow + Groq/Whisper)**. Ele permite gerenciar suas finanças pessoais através de linguagem natural, áudio e comandos inteligentes.

---

## ✨ Funcionalidades Principais

### 1. 📝 Registro por Linguagem Natural (Texto ou Áudio)
Envie mensagens comuns ou notas de voz:
- *"Gastei 50 reais com Uber agora"* (Registra uma despesa)
- *"Recebi 2000 do salário hoje"* (Registra uma receita)
- *"Comprei uma pizza por 80 ontem"* (A IA extrai a data retroativa)
- **🎙️ Suporte a Áudio:** Transcrição automática via **Groq (Whisper-large-v3)**.

### 2. 💳 Gestão de Parcelamentos (Inteligência Temporal)
O bot entende compras parceladas e projeta os gastos nos meses futuros:
- *"Comprei uma TV de 1200 em 10x"*
- **O que o bot faz:** Gera 10 registros automáticos (ex: "TV 1/10", "TV 2/10") distribuídos nos próximos 10 meses.
- **Desfazer em Cascata:** Ao clicar em "Desfazer" em uma compra parcelada, o bot remove **todas** as parcelas futuras de uma vez.

### 3. 🎯 Metas de Orçamento e Alertas Proativos
Defina limites de gastos por categoria e receba avisos automáticos:
- *"Minha meta para Mercado é 800 reais"*
- **Alertas Automáticos:**
  - ⚠️ **90% do limite:** Aviso de atenção quando você estiver próximo de estourar a meta.
  - 🛑 **100% ou mais:** Alerta crítico de limite ultrapassado.

### 4. 📊 Relatórios Inteligentes e Saldo Líquido
Peça relatórios de diferentes períodos:
- *"Quanto eu gastei esse mês?"* / *"Relatório dessa semana"*
- **O que o relatório mostra:**
  - Listagem detalhada com emojis (🟢 Receita / 🔴 Despesa).
  - Soma total de Entradas e Saídas.
  - **Saldo Líquido** do período consultado.

### 5. 🖱️ Interface Ágil (Botões Inline)
- **Botão Desfazer:** Após cada registro, um botão interativo permite cancelar o lançamento instantaneamente, editando a mensagem original para confirmar a exclusão.

---

## 🛠️ Tecnologias Utilizadas

- **Laravel 11:** Framework PHP moderno.
- **MongoDB:** Banco de dados NoSQL para flexibilidade total de esquemas.
- **Langflow:** Orquestração de IA para extração de entidades (NLP).
- **Groq (Whisper):** Transcrição de áudio em tempo real com alta precisão.
- **Telegram Bot API:** Interface de usuário.

---

## 🚀 Como Configurar

### 1. Requisitos
- Docker e Docker Compose.
- Bot no Telegram ([@BotFather](https://t.me/botfather)).
- API Key do **Groq** (para áudio).
- Instância do **Langflow** com o fluxo configurado.

### 2. Instalação (Docker)
```bash
docker-compose up -d --build
docker exec -it backend-app-1 bash
composer install
php artisan key:generate
php artisan install:api
```

---

## 🔒 Segurança
O bot utiliza a trava `ALLOWED_TELEGRAM_USERS`. Apenas IDs de usuários autorizados no `.env` podem interagir com o bot, garantindo a privacidade dos seus dados financeiros.

---
