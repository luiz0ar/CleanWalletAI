<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Extração e Validação Inicial
        $message = $request->input('message');
        if (!$message || !isset($message['text'])) {
            return response()->json(['status' => 'ok']); // Retorna 200 pro Telegram não reenviar
        }

        $telegramId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        $text = $message['text'];

        // 2. Segurança: Allowlist
        if (!$this->isUserAllowed($telegramId)) {
            Log::warning("Tentativa de acesso não autorizada. Telegram ID: {$telegramId}");
            return response()->json(['status' => 'forbidden']);
        }

        try {
            // 3. Processamento via Langflow (IA)
            $extractedData = $this->processViaLangflow($text);

            // 4. Persistência no MongoDB Atlas
            $expense = Expense::create([
                'telegram_id' => $telegramId,
                'valor'       => $extractedData['valor'],
                'categoria'   => $extractedData['categoria'],
                'descricao'   => $extractedData['descricao'],
                'data'        => $extractedData['data'], // Data já formatada pela IA
            ]);

            // 5. Feedback para o Usuário
            $this->sendTelegramMessage($chatId, $expense);

        } catch (\Exception $e) {
            Log::error("Erro ao processar gasto: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, null, "❌ Ocorreu um erro ao registrar a despesa.");
        }

        return response()->json(['status' => 'success']);
    }

    private function isUserAllowed(int $telegramId): bool
    {
        $allowedUsers = explode(',', env('ALLOWED_TELEGRAM_USERS', ''));
        return in_array((string)$telegramId, $allowedUsers);
    }

   private function processViaLangflow(string $text): array
    {
        $currentDate = now()->toIso8601String(); 
        
        // O ID exato do seu fluxo
        $flowId = 'd496f55f-9865-43c8-9c9b-ce15f4be0657'; 
        
        // Como o Laravel e o Langflow estão no mesmo docker-compose,
        // o Laravel consegue chamar o Langflow pelo nome do serviço ('langflow')
        $langflowUrl = "http://langflow:7860/api/v1/run/{$flowId}";

        $response = Http::withHeaders([
            'x-api-key' => env('LANGFLOW_API_KEY') // A chave que você gerou na interface
        ])->post($langflowUrl, [
            'input_value' => $text,
            'input_type'  => 'chat',
            'output_type' => 'chat',
            'tweaks' => [
                // Usamos o nome visual do nó exatamente como está na tela
                'New Flow' => [ 
                    'data_atual' => $currentDate
                ]
            ]
        ]);

        if (!$response->successful()) {
            \Illuminate\Support\Facades\Log::error("Erro Langflow: " . $response->body());
            throw new \Exception("Falha na API do Langflow.");
        }

        $aiTextResponse = $response->json('outputs.0.outputs.0.results.message.text');
        
        return json_decode($aiTextResponse, true);
    }

    private function sendTelegramMessage(int $chatId, ?Expense $expense, string $errorMessage = null): void
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        if ($errorMessage) {
            $text = $errorMessage;
        } else {
            $valorFormatado = number_format($expense->valor, 2, ',', '.');
            $text = "✅ *Registro Salvo*\n"
                  . "R$ {$valorFormatado}\n"
                  . "{$expense->descricao}\n"
                  . "_{$expense->categoria}_";
        }

        Http::post($url, [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}