# TelegramWallet — Backend (Laravel 11)

Este diretório contém o scaffold do backend em Laravel 11.

Quick start (local, usando Docker):

1. Copie `.env.example` para `.env` e ajuste variáveis.
2. Build e subir containers (na raiz do projeto):

```bash
docker-compose up -d --build
```

3. Se necessário, entre no container do `app` e gere a `APP_KEY`:

```bash
docker exec -it <app_container_name> bash
composer install
php artisan key:generate
```

4. Configure o webhook do Telegram apontando para `https://<your-host>/api/webhook/telegram`.

Notas:
- O `Dockerfile` executa `composer create-project` durante o build caso não exista código montado.
- Use `ALLOWED_TELEGRAM_USERS` para controlar quem pode interagir com o bot.
