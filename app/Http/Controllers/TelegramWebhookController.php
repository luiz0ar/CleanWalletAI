<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

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

            if ($intencao === 'meta') {
                $this->saveBudget($extractedData, $telegramId, $chatId);
                return response()->json(['status' => 'success']);
            }

            if ($intencao === 'relatorio') {
                $this->generateReport($extractedData, $telegramId, $chatId);
                return response()->json(['status' => 'success']);
            }

            $categoriaFinal = !empty($extractedData['categoria']) ? ucfirst($extractedData['categoria']) : 'Outros';
            $parcelas = (int) ($extractedData['parcelas'] ?? 1);
            $valorTotal = floatval($extractedData['valor'] ?? 0);
            $dataBase = Carbon::parse($extractedData['data'] ?? now());

            if ($parcelas > 1 && $intencao === 'gasto') {
                $groupId = (string) Str::uuid();
                $valorParcela = $valorTotal / $parcelas;

                for ($i = 0; $i < $parcelas; $i++) {
                    $dataParcela = $dataBase->copy()->addMonths($i);
                    $numParcela = $i + 1;

                    Expense::create([
                        'telegram_id'   => $telegramId,
                        'valor'         => $valorParcela,
                        'categoria'     => $categoriaFinal,
                        'descricao'     => ($extractedData['descricao'] ?? 'Sem descrição') . " ({$numParcela}/{$parcelas})",
                        'data'          => $dataParcela->toDateString(),
                        'tipo'          => 'despesa',
                        'parcelas'      => $parcelas,
                        'valor_total'   => $valorTotal,
                        'valor_parcela' => $valorParcela,
                        'group_id'      => $groupId,
                    ]);
                }
                $valorFormatado = number_format($valorParcela, 2, ',', '.');
                $totalFormatado = number_format($valorTotal, 2, ',', '.');
                $mensagemSucesso = "✅ *Compra Parcelada Salva*\n💸 {$parcelas}x de R$ {$valorFormatado}\n💰 Total: R$ {$totalFormatado}\n📝 {$extractedData['descricao']}\n🏷️ {$categoriaFinal}";

                $buttons = [
                    'inline_keyboard' => [[['text' => '🗑️ Desfazer Tudo', 'callback_data' => "undo_group_{$groupId}"]]]
                ];
                $this->sendTelegramMessage($chatId, $mensagemSucesso, $buttons);
                $this->checkBudgetLimit($telegramId, $categoriaFinal, $chatId, $valorParcela);
            } else {
                $expense = Expense::create([
                    'telegram_id' => $telegramId,
                    'valor'       => $valorTotal,
                    'categoria'   => $categoriaFinal,
                    'descricao'   => $extractedData['descricao'],
                    'data'        => $dataBase->toDateString(),
                    'tipo'        => ($intencao === 'receita') ? 'receita' : 'despesa',
                ]);

                $valorFormatado = number_format($expense->valor, 2, ',', '.');
                $descricaoStr = $expense->descricao ? ucfirst($expense->descricao) : 'Sem descrição';
                $emoji = ($expense->tipo === 'receita') ? '💰' : '💸';
                $prefixo = ($expense->tipo === 'receita') ? 'Receita' : 'Despesa';

                $mensagemSucesso = "✅ *{$prefixo} Salva*\n{$emoji} R$ {$valorFormatado}\n📝 {$descricaoStr}\n🏷️ {$expense->categoria}";

                $buttons = [
                    'inline_keyboard' => [[['text' => '🗑️ Desfazer', 'callback_data' => "undo_{$expense->_id}"]]]
                ];

                $this->sendTelegramMessage($chatId, $mensagemSucesso, $buttons);

                if ($expense->tipo === 'despesa') {
                    $this->checkBudgetLimit($telegramId, $expense->categoria, $chatId, $expense->valor);
                }
            }
        } catch (\Exception $e) {
            Log::error("Erro ao processar mensagem: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, "❌ Ocorreu um erro ao processar sua solicitação.");
        }

        return response()->json(['status' => 'success']);
    }

    private function handleCallbackQuery(array $callbackQuery)
    {
        $data = $callbackQuery['data'];
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];

        if (strpos($data, 'undo_group_') === 0) {
            $groupId = str_replace('undo_group_', '', $data);
            Expense::where('group_id', $groupId)->delete();
            $this->editTelegramMessage($chatId, $messageId, "🗑️ *Parcelas Canceladas*\nTodos os lançamentos do grupo foram removidos.");
        } elseif (strpos($data, 'undo_') === 0) {
            $id = str_replace('undo_', '', $data);
            $expense = Expense::find($id);

            if ($expense) {
                $valor = number_format($expense->valor, 2, ',', '.');
                $expense->delete();
                $this->editTelegramMessage($chatId, $messageId, "🗑️ *Registro Cancelado*\nO lançamento de R$ {$valor} foi removido.");
            } else {
                $this->editTelegramMessage($chatId, $messageId, "⚠️ Este registro já foi removido.");
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function saveBudget(array $extractedData, $telegramId, $chatId)
    {
        $categoria = ucfirst($extractedData['categoria'] ?? 'Geral');
        $limite = floatval($extractedData['valor'] ?? 0);

        Budget::updateOrCreate(
            ['telegram_id' => $telegramId, 'categoria' => $categoria],
            ['limite' => $limite]
        );

        $valorFmt = number_format($limite, 2, ',', '.');
        $this->sendTelegramMessage($chatId, "🎯 *Meta Definida!*\nSeu limite mensal para {$categoria} agora é R$ {$valorFmt}.");
    }

    private function checkBudgetLimit($telegramId, $categoria, $chatId, $valorGastoAgora = 0)
    {
        $budget = Budget::where('telegram_id', $telegramId)->where('categoria', $categoria)->first();

        if (!$budget || $budget->limite <= 0) {
            return;
        }

        $somaAtual = Expense::where('telegram_id', $telegramId)
            ->where('categoria', $categoria)
            ->where('tipo', 'despesa')
            ->whereBetween('data', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('valor');

        $somaAnterior = $somaAtual - $valorGastoAgora;

        $percentualAtual = ($somaAtual / $budget->limite) * 100;
        $percentualAnterior = ($somaAnterior / $budget->limite) * 100;

        $limiteFmt = number_format($budget->limite, 2, ',', '.');
        $consumidoFmt = number_format($somaAtual, 2, ',', '.');
        $percentualFmt = number_format($percentualAtual, 1);

        $cruzou50  = ($percentualAtual >= 50 && $percentualAnterior < 50);
        $cruzou75  = ($percentualAtual >= 75 && $percentualAnterior < 75);
        $cruzou90  = ($percentualAtual >= 90 && $percentualAnterior < 90);
        $cruzou100 = ($percentualAtual >= 100 && $percentualAnterior < 100);

        if ($cruzou100) {
            $this->sendTelegramMessage($chatId, "🛑 *Limite Estourado! ({$categoria})*\nVocê ultrapassou sua meta de R$ {$limiteFmt}.\nTotal consumido: R$ {$consumidoFmt}.");
        } elseif ($cruzou50 || $cruzou75 || $cruzou90) {
            $this->sendTelegramMessage($chatId, "⚠️ *Atenção! ({$categoria})*\nVocê já consumiu {$percentualFmt}% da sua meta mensal (R$ {$limiteFmt}).\nTotal consumido: R$ {$consumidoFmt}.");
        }
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
            $mensagem .= "🟢 Entradas: R$ " . number_format($totalReceitas, 2, ',', '.') . "\n";
            $mensagem .= "🔴 Saídas: R$ " . number_format($totalDespesas, 2, ',', '.') . "\n";
            $mensagem .= "💰 Saldo Líquido: R$ " . number_format($saldo, 2, ',', '.');
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

        $cleanJson = preg_replace('/```json\s?|```\s?/', '', $aiTextResponse);
        $decoded = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Erro ao decodificar JSON do Langflow: " . json_last_error_msg());
            throw new \Exception("A IA devolveu um formato inválido.");
        }

        return [
            'intencao'  => $decoded['intencao'] ?? 'gasto',
            'valor'     => floatval($decoded['valor'] ?? 0),
            'categoria' => $decoded['categoria'] ?? null,
            'descricao' => $decoded['descricao'] ?? null,
            'data'      => $decoded['data'] ?? now()->toDateString(),
            'periodo'   => $decoded['periodo'] ?? 'mes',
            'parcelas'  => $decoded['parcelas'] ?? 1,
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
