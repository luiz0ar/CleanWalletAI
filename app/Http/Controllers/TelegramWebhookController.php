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
        if ($request->has('callback_query')) {
            return $this->handleCallbackQuery($request->input('callback_query'));
        }

        $message = $request->input('message');
        if (!$message) {
            return response()->json(['status' => 'ok']);
        }

        $telegramId = $message['from']['id'];
        $chatId = $message['chat']['id'];

        if (!$this->isUserAllowed($telegramId)) {
            Log::warning("Tentativa de acesso não autorizada. Telegram ID: {$telegramId}");
            return response()->json(['status' => 'forbidden']);
        }

        $text = null;

        if (isset($message['text'])) {
            $text = $message['text'];
        } elseif (isset($message['voice'])) {
            $this->sendTelegramMessage($chatId, "🎧 Processando áudio...");
            try {
                $fileId = $message['voice']['file_id'];
                $audioPath = $this->downloadTelegramAudio($fileId);
                $text = $this->transcribeAudio($audioPath);

                if (file_exists($audioPath)) {
                    unlink($audioPath);
                }

            } catch (\Exception $e) {
                Log::error("Erro no áudio: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "❌ Erro ao converter o áudio para texto.");
                return response()->json(['status' => 'success']);
            }
        } else {
            return response()->json(['status' => 'ok']);
        }

        if (trim(strtolower($text)) === '/desfazer') {
            $this->undoLastExpense($telegramId, $chatId);
            return response()->json(['status' => 'success']);
        }

        try {
            $extractedData = $this->processViaLangflow($text);
            $intencao = $extractedData['intencao'] ?? 'gasto';

            if ($intencao === 'relatorio') {
                $this->generateReport($extractedData, $telegramId, $chatId);
                return response()->json(['status' => 'success']);
            }

            $categoriaFinal = !empty($extractedData['categoria']) ? ucfirst($extractedData['categoria']) : 'Outros';

            $expense = Expense::create([
                'telegram_id' => $telegramId,
                'valor'       => $extractedData['valor'],
                'categoria'   => $categoriaFinal,
                'descricao'   => $extractedData['descricao'],
                'data'        => $extractedData['data'],
                'tipo'        => ($intencao === 'receita') ? 'receita' : 'despesa',
            ]);

            $valorFormatado = number_format($expense->valor, 2, ',', '.');
            $descricaoStr = $expense->descricao ? ucfirst($expense->descricao) : 'Sem descrição';
            $emoji = ($expense->tipo === 'receita') ? '💰' : '💸';
            $prefixo = ($expense->tipo === 'receita') ? 'Receita' : 'Despesa';

            $mensagemSucesso = "✅ *{$prefixo} Salva*\n{$emoji} R$ {$valorFormatado}\n {$descricaoStr}\n {$expense->categoria}";

            $buttons = [
                'inline_keyboard' => [
                    [
                        ['text' => '🗑️ Desfazer', 'callback_data' => "undo_{$expense->_id}"]
                    ]
                ]
            ];

            $this->sendTelegramMessage($chatId, $mensagemSucesso, $buttons);
        } catch (\Exception $e) {
            Log::error("Erro ao processar mensagem: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "❌ Ocorreu um erro ao processar sua solicitação.");
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Trata cliques em botões inline
     */
    private function handleCallbackQuery(array $callbackQuery)
    {
        $data = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];

        if (strpos($data, 'undo_') === 0) {
            $id = str_replace('undo_', '', $data);
            $expense = Expense::find($id);

            if ($expense) {
                $expense->delete();
                $this->editTelegramMessage($chatId, $messageId, "🗑️ *Registro Cancelado*\nO valor de R$ " . number_format($expense->valor, 2, ',', '.') . " em {$expense->categoria} foi apagado.");
            } else {
                $this->editTelegramMessage($chatId, $messageId, "⚠️ *Aviso*\nEste registro já foi desfeito ou não existe mais.");
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function downloadTelegramAudio(string $fileId): string
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');

        $response = Http::get("https://api.telegram.org/bot{$botToken}/getFile", [
            'file_id' => $fileId
        ]);

        if (!$response->successful() || !isset($response['result']['file_path'])) {
            throw new \Exception("Não foi possível obter o caminho do arquivo do Telegram.");
        }

        $filePath = $response['result']['file_path'];

        $audioUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $audioData = file_get_contents($audioUrl);

        $localPath = storage_path("app/temp_audio_{$fileId}.ogg");
        file_put_contents($localPath, $audioData);

        return $localPath;
    }

    private function transcribeAudio(string $filePath): string
    {
        $apiKey = env('GROQ_API_KEY');

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}"
        ])->attach(
            'file',
            file_get_contents($filePath),
            'audio.ogg'
        )->post('https://api.groq.com/openai/v1/audio/transcriptions', [
            'model'           => 'whisper-large-v3-turbo',
            'response_format' => 'json',
            'language'        => 'pt'
        ]);

        if (!$response->successful()) {
            Log::error("Erro na transcrição Groq: " . $response->body());
            throw new \Exception("Falha na API de transcrição.");
        }

        return $response->json('text');
    }

    /**
     * Remove o último registro do usuário (via comando /desfazer)
     */
    private function undoLastExpense($telegramId, $chatId)
    {
        $lastExpense = Expense::where('telegram_id', $telegramId)->latest('created_at')->first();

        if (!$lastExpense) {
            $this->sendTelegramMessage($chatId, "⚠️ Não encontrei nenhum gasto recente para desfazer.");
            return;
        }

        $lastExpense->delete();
        $this->sendTelegramMessage($chatId, "Desfeito! O registro de R$ " . number_format($lastExpense->valor, 2, ',', '.') . " em '{$lastExpense->categoria}' foi apagado.");
    }

    /**
     * Gera os relatórios baseados na extração da IA
     */
    private function generateReport(array $extractedData, $telegramId, $chatId)
    {
        $query = Expense::where('telegram_id', $telegramId);

        $periodo = $extractedData['periodo'] ?? 'mes';
        $startDate = match ($periodo) {
            'semana' => now()->startOfWeek(),
            'ano'    => now()->startOfYear(),
            'total'  => null,
            default  => now()->startOfMonth(),
        };

        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }

        $categoria = $extractedData['categoria'] ?? null;
        if (!empty($categoria)) {
            $query->where('categoria', 'like', "%{$categoria}%");
        }

        $despesas = $query->orderBy('created_at', 'asc')->get();

        $periodoStr = ucfirst($periodo);
        $mensagem = "📊 *Relatório ({$periodoStr})*\n\n";

        if ($despesas->isEmpty()) {
            $mensagem .= "Nenhum registro encontrado.";
        } else {
            $totalReceitas = 0;
            $totalDespesas = 0;

            foreach ($despesas as $exp) {
                $dataRegistro = $exp->data ?? $exp->created_at;
                $dataFmt = \Carbon\Carbon::parse($dataRegistro)->format('d/m');
                $val = number_format($exp->valor, 2, ',', '.');
                $emoji = ($exp->tipo === 'receita') ? '🟢' : '🔴';

                $desc = $exp->descricao ? ucfirst($exp->descricao) : $exp->categoria;

                $mensagem .= "{$emoji} {$dataFmt} - R$ {$val} ({$desc})\n";

                if ($exp->tipo === 'receita') {
                    $totalReceitas += $exp->valor;
                } else {
                    $totalDespesas += $exp->valor;
                }
            }

            $saldo = $totalReceitas - $totalDespesas;

            $mensagem .= "\n-------------------\n";
            $mensagem .= "🟢 *Entradas:* R$ " . number_format($totalReceitas, 2, ',', '.') . "\n";
            $mensagem .= "🔴 *Saídas:* R$ " . number_format($totalDespesas, 2, ',', '.') . "\n";
            $mensagem .= "💰 *Saldo Líquido:* R$ " . number_format($saldo, 2, ',', '.');
        }

        $this->sendTelegramMessage($chatId, $mensagem);
    }

    private function isUserAllowed(int $telegramId): bool
    {
        $allowedUsers = env('ALLOWED_TELEGRAM_USERS', '');

        if ($allowedUsers === '*') {
            return true;
        }

        $allowedList = explode(',', $allowedUsers);
        return in_array((string)$telegramId, $allowedList);
    }

    private function processViaLangflow(string $text): array
    {
        $currentDate = now()->toIso8601String();

        $flowId = env('LANGFLOW_FLOW_ID');

        $langflowUrl = "http://langflow:7860/api/v1/run/{$flowId}";

        $response = Http::withHeaders([
            'x-api-key' => env('LANGFLOW_API_KEY')
        ])->post($langflowUrl, [
            'input_value' => $text,
            'input_type'  => 'chat',
            'output_type' => 'chat',
            'tweaks' => [
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

        $cleanJson = preg_replace('/```json\s?|\s?```/', '', $aiTextResponse);
        $decoded = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Erro ao decodificar JSON do Langflow: " . json_last_error_msg());
            throw new \Exception("A IA devolveu um formato inválido.");
        }

        return [
            'intencao'  => $decoded['intencao'] ?? 'gasto',
            'valor'     => floatval($decoded['valor'] ?? 0),
            'categoria' => $decoded['categoria'] ?? 'Outros',
            'descricao' => $decoded['descricao'] ?? null,
            'data'      => $decoded['data'] ?? now()->toDateString(),
            'periodo'   => $decoded['periodo'] ?? 'mes',
        ];
    }

    private function sendTelegramMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        Http::post($url, $payload);
    }

    private function editTelegramMessage(int $chatId, int $messageId, string $text): void
    {
        $botToken = env('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/bot{$botToken}/editMessageText";

        Http::post($url, [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
}
