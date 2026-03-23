# 🤖 CleanWalletAI - Finance Assistant Bot

CleanWalletAI é um assistente financeiro inteligente para Telegram, construído com **Laravel 11**, **MongoDB** e **Langflow (IA)**. Ele permite registrar gastos e receitas usando linguagem natural, gera relatórios automáticos e oferece uma interface ágil com botões interativos.

---

## ✨ Funcionalidades Atuais

### 1. 📝 Registro por Linguagem Natural
Envie mensagens comuns como:
- *"Gastei 50 reais com Uber agora"* (Registra uma despesa)
- *"Recebi 2000 do salário hoje"* (Registra uma receita)
- *"Comprei uma pizza por 80 ontem"* (A IA extrai a data retroativa)

### 2. 📊 Relatórios Inteligentes
Peça relatórios de diferentes períodos:
- *"Quanto eu gastei esse mês?"*
- *"Me mostre as despesas dessa semana"*
- *"Relatório geral de 2026"*
- **O que o relatório mostra:** Soma de entradas, soma de saídas e o **Saldo Líquido**.

### 3. 🖱️ Interface com Botões (UX)
- **Botão Desfazer:** Após cada registro, o bot exibe um botão `[ 🗑️ Desfazer ]`. Ao clicar, o registro é removido instantaneamente e a mensagem é editada para confirmar a exclusão.
- **Comando Manual:** Você também pode usar `/desfazer` para apagar o último registro enviado.

### 4. 🎙️ Suporte a Áudio (Fase Inicial)
- O bot identifica o envio de mensagens de voz. (Transcrição automática via Groq)*.

---

## 🛠️ Tecnologias Utilizadas

- **Laravel 11:** Core do sistema.
- **MongoDB:** Banco de dados NoSQL (Schema-less) para flexibilidade total nos registros.
- **Langflow:** Motor de IA para processamento de linguagem natural (NLP).
- **Telegram Bot API:** Interface de comunicação com o usuário.

---

## 🚀 Como Configurar

### 1. Requisitos
- Docker e Docker Compose.
- Um Bot no Telegram (criado via [@BotFather](https://t.me/botfather)).
- Uma conta/instância do Langflow com o fluxo de extração configurado.

### 2. Variáveis de Ambiente (`.env`)
Certifique-se de configurar as seguintes chaves:

```env
# Telegram
TELEGRAM_BOT_TOKEN=seu_token_aqui
ALLOWED_TELEGRAM_USERS=seu_id_telegram,outro_id  # Ou '*' para liberar geral

# Langflow
LANGFLOW_API_KEY=sua_chave_api
LANGFLOW_FLOW_ID=id_do_seu_fluxo
```

### 3. Instalação (Docker)
Na raiz do projeto, execute:

```bash
docker-compose up -d --build
```

Após subir os containers, entre no container `app` para rodar os comandos iniciais (apenas na primeira vez):

```bash
docker exec -it backend-app-1 bash
composer install
php artisan key:generate
php artisan install:api
```

### 4. Configurar Webhook
Aponte o webhook do seu bot para o endpoint da API:
`https://seu-dominio.com/api/webhook/telegram`

---

## 🔒 Segurança
O bot possui uma trava de segurança via `ALLOWED_TELEGRAM_USERS`. Apenas IDs de usuários listados nesta variável podem interagir com o bot, evitando registros indesejados por terceiros.

---

## 📈 Próximos Passos
- [ ] Transcrição de áudio via API do Groq (Whisper).
- [ ] Gráficos de pizza/barra gerados dinamicamente no Telegram.
- [ ] Exportação de dados para Excel/CSV.
- [ ] Sistema de categorias personalizáveis por usuário.
