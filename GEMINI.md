# Plano de Implementação: CleanWalletAI - Nível 1

## 🎯 Objetivo
Evoluir o bot de um simples "registrador de gastos" para um assistente financeiro completo, com suporte a receitas, interações rápidas por botões e entrada por áudio, mantendo a arquitetura atual (Laravel + MongoDB + Langflow).

---

## 🚀 Fase 1: Controle de Receitas e Saldo Líquido
**Meta:** Permitir que o bot entenda quando o usuário ganha dinheiro (salário, freela, pix recebido) e calcular o saldo final no relatório.

### 1.1. Atualização do Motor de IA (Langflow)
* **Ação:** Editar o `Prompt Template` no Langflow.
* **Mudança:** Adicionar uma nova intenção chamada `receita`. 
* **Nova Estrutura JSON Esperada:**
    ```json
    {
      "intencao": "gasto", "receita" ou "relatorio",
      "valor": 150.00,
      "categoria": "Salário",
      "descricao": "freela tech",
      "data": "YYYY-MM-DD",
      "periodo": null
    }
    ```
* **Few-Shot Prompting:** Adicionar exemplos claros de receitas no prompt (ex: *"Recebi 500 reais do cliente X" -> intencao: receita*).

### 1.2. Evolução do Schema (MongoDB & Laravel)
* **Ação:** O MongoDB é *schema-less*, então não precisamos rodar *migrations*, mas precisamos atualizar o Model e o Controller.
* **Mudança:** O Controller deve ler `$extractedData['intencao']` e salvar no banco um novo campo chamado `tipo` (`despesa` ou `receita`).

### 1.3. Refatoração dos Relatórios (`generateReport`)
* **Ação:** Atualizar a query do Eloquent.
* **Mudança:** Em vez de somar tudo, o Laravel fará duas somas separadas (uma onde `tipo == 'receita'` e outra onde `tipo == 'despesa'`). O relatório final exibirá:
    * 🟢 Entradas: R$ X
    * 🔴 Saídas: R$ Y
    * 💰 Saldo Líquido: R$ Z

---

## 🖱️ Fase 2: UX com Botões Inline (Ação de Desfazer)
**Meta:** Eliminar a necessidade de o usuário digitar `/desfazer`, substituindo por um botão clicável acoplado à mensagem de sucesso.

### 2.1. O Botão no Telegram (`reply_markup`)
* **Ação:** Atualizar o método `sendTelegramMessage` no `TelegramWebhookController`.
* **Mudança:** Permitir o envio de um array de botões (`inline_keyboard`). Quando um gasto for salvo, enviar um botão `[ 🗑️ Desfazer ]` que contenha um `callback_data` com o ID do registro no Mongo (ex: `undo_65f9a8b7c4...`).

### 2.2. Interceptando o Clique (`callback_query`)
* **Ação:** Atualizar o método `handle()` para escutar cliques.
* **Mudança:** O Telegram não envia uma `message` quando o usuário clica num botão, ele envia uma `callback_query`. O Laravel vai interceptar isso, extrair o ID, deletar o registro específico no MongoDB e responder ao Telegram.

### 2.3. Feedback Visual (Edit Message)
* **Ação:** Limpar o chat.
* **Mudança:** Em vez de mandar uma nova mensagem dizendo "Desfeito", o Laravel usará o método `editMessageText` da API do Telegram para alterar a mensagem original de "✅ Registro Salvo" para "🗑️ Registro Cancelado", desativando o botão.

---

## 🎙️ Fase 3: Transcrição de Áudio (Voice-to-Text)
**Meta:** Permitir que o usuário envie notas de voz no Telegram e o sistema processe normalmente.

### 3.1. Interceptação do Áudio
* **Ação:** Atualizar o `handle()` para identificar `message.voice`.
* **Mudança:** O Laravel detectará que não há texto, mas sim um arquivo de áudio (`file_id`).

### 3.2. Download do Arquivo (Telegram API)
* **Ação:** Criar um método para baixar o arquivo `.ogg`.
* **Mudança:** Fazer uma requisição para `getFile` na API do Telegram para pegar o path, e depois fazer o download do arquivo de áudio temporariamente para o servidor Docker.

### 3.3. Transcrição (Whisper / Groq API)
* **Ação:** Converter áudio em texto de forma rápida.
* **Estratégia:** Integrar uma API de transcrição (Recomendação: usar a API do *Groq* com o modelo *Whisper*, que é absurdamente rápida e tem um tier gratuito generoso). O Laravel envia o `.ogg`, recebe a string de texto ("Gastei 50 no posto") e injeta essa string diretamente na função que já chama o Langflow. O resto do sistema nem saberá que a origem foi um áudio.