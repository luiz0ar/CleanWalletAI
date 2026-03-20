Passo 1: O Merge Manual (No seu SO)
Abra o seu editor de código na sua máquina e copie apenas os arquivos de negócio do backend_backup (ou backend) para o novo backend_tmp:

O seu app/Http/Controllers/TelegramWebhookController.php

O seu Model app/Models/Expense.php

O seu arquivo de rotas routes/api.php

O seu arquivo .env (certifique-se de que as credenciais do Mongo, Telegram e Langflow estão lá).

Passo 2: A Troca de Guarda
Ainda no seu sistema operacional:

Renomeie a pasta original backend para backend_old (só por garantia).

Renomeie a pasta backend_tmp para backend.

Passo 3: Instalando a dependência do Mongo
Como esse é um scaffold fresquinho do Laravel 13, ele ainda não sabe conversar com o MongoDB Atlas. Precisamos instalar o pacote oficial.
Abra o terminal e rode:

Bash
docker exec -it -w /var/www/backend fin_bot_app composer require mongodb/laravel-mongodb
Passo 4: Ativando a API
Agora que o motor do Laravel está rodando na pasta oficial com o artisan bonitinho, vamos injetar o roteamento de API (e dizer sim se ele perguntar de migrations):

Bash
docker exec -it -w /var/www/backend fin_bot_app php artisan install:api
Passo 5: Reboot e Teste
Por via das dúvidas, vamos reiniciar o contêiner do PHP para ele ler a nova estrutura de pastas, e limpar o cache de rotas:

Bash
docker compose restart app
docker exec -it -w /var/www/backend fin_bot_app php artisan route:clear