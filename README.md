# 🤖 CleanWalletAI - Assistente Financeiro Inteligente

CleanWalletAI é um assistente financeiro inteligente para Telegram, construído com **Laravel 11**, **MongoDB** e **IA Multimodal (Gemini Vision + Langflow + Groq/Whisper)**. Ele permite que você gerencie suas finanças pessoais através de linguagem natural, notas de voz e fotos de comprovantes.

---

## ✨ Funcionalidades Principais

### 1. 📸 Visão Cognitiva Multimodal
Envie fotos de cupons fiscais, boletos, comprovantes de Pix ou extratos bancários:
- **Como funciona:** O bot utiliza o **Google Gemini 2.5 Flash** para realizar a extração cognitiva da imagem.
- **Dados Extraídos:** Identifica automaticamente o Valor Total, Estabelecimento (Descrição), Data e Categoria.
- **Fluxo Unificado:** Dados extraídos de imagens seguem a mesma lógica do texto, incluindo parcelamentos e alertas de orçamento.

### 2. 📝 Processamento de Linguagem Natural (Texto e Áudio)
Envie mensagens comuns ou notas de voz:
- *"Gastei 50 reais no Uber agora"* (Registra uma despesa)
- *"Recebi 2000 do salário hoje"* (Registra uma receita)
- **🎙️ Suporte Completo a Áudio:** Transcrição automática via **Groq (Whisper-large-v3)**.

### 3. 💳 Gestão de Parcelamentos
O bot entende compras parceladas e projeta gastos nos meses futuros:
- *"Comprei uma TV por 1200 em 10x"*
- **Projeção Automática:** Gera múltiplos registros (ex: "TV 1/10", "TV 2/10") distribuídos ao longo dos próximos meses.
- **Exclusão em Cascata:** Clicar em "Desfazer" em uma compra parcelada remove todas as parcelas futuras de uma vez.

### 4. 🎯 Metas de Orçamento e Alertas Proativos
Defina limites mensais de gastos por categoria:
- *"Minha meta para Mercado é 800 reais"*
- **Alertas Inteligentes:** Receba notificações quando atingir 90% ou 100% do seu limite definido.

### 5. 📊 Relatórios Inteligentes
Peça relatórios de diferentes períodos:
- *"Quanto eu gastei este mês?"* / *"Mostre o relatório desta semana"*
- **Saída:** Lista detalhada com emojis (🟢 Receita / 🔴 Despesa) e Saldo Líquido.

---

## 🛠️ Stack Tecnológica

- **Laravel 11:** Framework PHP Moderno.
- **MongoDB:** Banco de dados NoSQL para registros financeiros flexíveis.
- **Google Gemini 1.5 Flash:** IA Multimodal para interpretação de imagens.
- **Langflow:** Orquestração de IA para extração de entidades de texto.
- **Groq (Whisper):** Transcrição de áudio para texto em alta velocidade.
- **Telegram Bot API:** Interface de usuário.

---

## 🚀 Guia de Configuração

### 1. Requisitos
- Docker e Docker Compose.
- Token do Bot do Telegram ([@BotFather](https://t.me/botfather)).
- **Chave da API do Google Gemini** (para Visão de Imagem).
- **Chave da API da Groq** (para Transcrição de Áudio).

### 2. Instalação
```bash
docker-compose up -d --build
docker exec -it backend-app-1 bash
composer install
php artisan key:generate
```

---

## 🔒 Segurança
Protegido por `ALLOWED_TELEGRAM_USERS`. Apenas IDs autorizados do Telegram podem interagir com o bot, garantindo que seus dados financeiros permaneçam privados.
