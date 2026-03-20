🌟 Visão Geral do Projeto
Um bot financeiro minimalista no Telegram para registro ágil de despesas cotidianas, projetado para uso pessoal e familiar (multi-usuário isolado). O sistema recebe mensagens em linguagem natural, utiliza IA (Langflow/Gemini) para estruturar os dados com precisão temporal e os armazena na nuvem. A arquitetura garante que cada usuário autorizado tenha seus gastos registrados e consultados de forma totalmente separada.

🏗️ Arquitetura e Stack Tecnológica
Interface / Mensageria: Telegram Bot API (Webhooks).

Backend: Laravel 11.

Motor de IA: Langflow (Processamento com o modelo Gemini).

Banco de Dados: MongoDB Atlas (NoSQL).

Infraestrutura Local/Deploy: 100% Dockerizado (3 contêineres: Laravel App, Nginx e Langflow).

🔒 Segurança e Controle de Acesso
Allowlist (Lista de Permissão): O Laravel utiliza uma variável de ambiente (ALLOWED_TELEGRAM_USERS) contendo os IDs do Telegram autorizados a interagir com o bot.

Isolamento de Dados: Toda requisição processada e salva no MongoDB inclui obrigatoriamente o telegram_id do remetente, garantindo que consultas e relatórios futuros não misturem as finanças dos usuários.

🔄 Fluxo de Execução (Data Flow)
Input do Usuário:

O usuário envia uma mensagem no chat do Telegram (Ex: "Uber 27 reais").

Recepção e Validação (Webhook):

O Telegram dispara um POST para a rota pública do Laravel.

O Controller recebe o payload, extrai o from.id (ou chat.id) e o texto da mensagem.

Validação de Segurança: O backend verifica se o id extraído está presente na variável de ambiente ALLOWED_TELEGRAM_USERS. Se não estiver, a requisição é descartada silenciosamente (retornando HTTP 200 para o Telegram).

Injeção de Contexto e Processamento (Langflow + Gemini):

O Laravel captura a data/hora exata do sistema (now()) e envia para a API do Langflow junto com o texto da mensagem.

A IA interpreta a linguagem natural e devolve um JSON estrito:
{"valor": 27.00, "categoria": "Transporte", "descricao": "Uber", "data": "2026-03-20T12:00:00Z"}.

Persistência (MongoDB Atlas):

O Laravel recebe o JSON, adiciona o campo de identificação do usuário e salva o documento na coleção de despesas:
{"telegram_id": "123456789", "valor": 27.00, "categoria": "Transporte", "descricao": "Uber", "data": "2026-03-20T12:00:00Z"}.

Feedback Imediato (Telegram):

O Laravel formata a resposta e faz uma requisição à API do Telegram para enviar a confirmação exclusivamente no chat de quem solicitou:

Plaintext
✅ Registro Salvo
R$ 27,00
Uber
🚀 Roadmap de Funcionalidades
Fase 1 (MVP Atual): Input de texto livre, extração via IA, validação de Allowlist, salvamento no banco de dados com ID do usuário e mensagem de confirmação.

Fase 2 (Relatórios Isolados): Comandos no Telegram (ex: /resumo mes) onde o Laravel consultará o MongoDB filtrando pelo telegram_id de quem pediu e pela data, devolvendo o balanço financeiro exclusivo daquele usuário.