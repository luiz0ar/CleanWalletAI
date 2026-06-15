<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Budget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class TelegramWebhookController extends Controller
{
    private $botToken;

    public function __construct()
    {
        $this->botToken = env('TELEGRAM_BOT_TOKEN');
    }

    public function handle(Request $request)
    {
        $updateId = $request->input('update_id');

        if ($updateId) {
            $isFirstRequest = Cache::add("telegram_update_{$updateId}", true, 120);

            if (!$isFirstRequest) {
                Log::info("Telegram Update {$updateId} duplicado ignorado por idempotência.");
                return response()->json(['status' => 'duplicate_ignored']);
            }
        }

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

        $extractedData = null;

        if (isset($message['photo'])) {
            $this->sendChatAction($chatId, 'upload_photo');
            $photoPath = null;

            try {
                $photo = end($message['photo']);
                $photoPath = $this->downloadTelegramFile($photo['file_id'], 'photo', 'jpg');
                $extractedData = $this->processImageWithGemini($photoPath);
            } catch (\Exception $e) {
                Log::error("Erro no processamento de imagem: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "❌ Erro ao analisar a imagem. Certifique-se de que o comprovante está legível.");
                return response()->json(['status' => 'success']);
            } finally {
                if ($photoPath && file_exists($photoPath)) {
                    unlink($photoPath);
                }
            }
        } elseif (isset($message['voice'])) {
            $this->sendChatAction($chatId, 'record_voice');
            $audioPath = null;

            try {
                $fileId = $message['voice']['file_id'];
                $audioPath = $this->downloadTelegramFile($fileId, 'audio', 'ogg');
                $text = $this->transcribeAudio($audioPath);
                $extractedData = $this->processViaLangflow($text);
            } catch (\Exception $e) {
                Log::error("Erro no áudio: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "❌ Erro ao converter o áudio para texto.");
                return response()->json(['status' => 'success']);
            } finally {
                if ($audioPath && file_exists($audioPath)) {
                    unlink($audioPath);
                }
            }
        } elseif (isset($message['text'])) {
            $text = $message['text'];

            if (trim(strtolower($text)) === '/desfazer') {
                $this->undoLastExpense($telegramId, $chatId);
                return response()->json(['status' => 'success']);
            }

            $this->sendChatAction($chatId, 'typing');

            try {
                $extractedData = $this->processViaLangflow($text);
            } catch (\Exception $e) {
                Log::error("Erro Langflow: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "❌ Erro ao processar o texto.");
                return response()->json(['status' => 'success']);
            }
        } else {
            return response()->json(['status' => 'ok']);
        }

        if ($extractedData) {
            try {
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

                    $valorFmt = number_format($valorParcela, 2, ',', '.');
                    $totalFmt = number_format($valorTotal, 2, ',', '.');
                    $msg = "✅ *Compra Parcelada Salva*\n💸 {$parcelas}x de R$ {$valorFmt}\n💰 Total: R$ {$totalFmt}\n📝 {$extractedData['descricao']}\n🏷️ {$categoriaFinal}";

                    $buttons = ['inline_keyboard' => [[['text' => '🗑️ Desfazer Tudo', 'callback_data' => "undo_group_{$groupId}"]]]];
                    $this->sendTelegramMessage($chatId, $msg, $buttons);
                    $this->checkBudgetLimit($telegramId, $categoriaFinal, $chatId, $valorParcela);
                } else {
                    $expense = Expense::create([
                        'telegram_id' => $telegramId,
                        'valor'       => $valorTotal,
                        'categoria'   => $categoriaFinal,
                        'descricao'   => $extractedData['descricao'] ?? 'Registro via Imagem/Voz',
                        'data'        => $dataBase->toDateString(),
                        'tipo'        => ($intencao === 'receita') ? 'receita' : 'despesa',
                    ]);

                    $valorFmt = number_format($expense->valor, 2, ',', '.');
                    $desc = $expense->descricao ? ucfirst($expense->descricao) : 'Sem descrição';
                    $emoji = ($expense->tipo === 'receita') ? '💰' : '💸';
                    $prefixo = ($expense->tipo === 'receita') ? 'Receita' : 'Despesa';

                    $msg = "✅ *{$prefixo} Salva*\n{$emoji} R$ {$valorFmt}\n📝 {$desc}\n🏷️ {$expense->categoria}";
                    $buttons = ['inline_keyboard' => [[['text' => '🗑️ Desfazer', 'callback_data' => "undo_{$expense->_id}"]]]];

                    $this->sendTelegramMessage($chatId, $msg, $buttons);

                    if ($expense->tipo === 'despesa') {
                        $this->checkBudgetLimit($telegramId, $expense->categoria, $chatId, $expense->valor);
                    }
                }
            } catch (\Exception $e) {
                Log::error("Erro na persistência: " . $e->getMessage());
                $this->sendTelegramMessage($chatId, "❌ Erro ao salvar os dados extraídos.");
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function processImageWithGemini(string $filePath): array
    {
        $apiKey = env('GEMINI_API_KEY');
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

        $base64Image = base64_encode(file_get_contents($filePath));

        $prompt = "Você é um analista financeiro especialista em extração de dados cognitivos.
 Analise a imagem anexa (pode ser um cupom fiscal, comprovante de pix, extrato ou boleto).

 Regras de Extração Cognitiva:
   1. VALOR: Encontre o valor total/final da transação. Ignore sub-totais ou trocos. Retorne como float.
   2. DESCRICAO: Identifique o nome simplificado do estabelecimento ou beneficiário.
   3. DATA: Encontre a data da emissão (YYYY-MM-DD). Se for ilegível, use a data atual.
   4. CATEGORIA: Deduza a categoria pelo nome do local (ex: Padaria -> Alimentação).
   5. INTENCAO: Defina se foi um 'gasto' ou 'receita'.

 Retorne EXCLUSIVAMENTE um JSON válido com esta estrutura:
 {
   \"intencao\": \"gasto\" ou \"receita\",
   \"valor\": float,
   \"categoria\": string,
   \"descricao\": string,
   \"data\": \"YYYY-MM-DD\",
   \"parcelas\": 1
 }";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => $base64Image
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ];

        $response = Http::post($url, $payload);

        if (!$response->successful()) {
            Log::error("Erro Gemini API: " . $response->body());
            throw new \Exception("Falha na comunicação com Gemini Vision.");
        }

        $result = $response->json();
        $jsonText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '{}';

        $cleanJson = preg_replace('/```json\s?|```\s?/', '', $jsonText);
        $decoded = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Erro JSON Gemini Vision: " . $jsonText);
            throw new \Exception("A IA de visão devolveu um formato inválido.");
        }

        return [
            'intencao'  => $decoded['intencao'] ?? 'gasto',
            'valor'     => floatval($decoded['valor'] ?? 0),
            'categoria' => $decoded['categoria'] ?? 'Outros',
            'descricao' => $decoded['descricao'] ?? null,
            'data'      => $decoded['data'] ?? now()->toDateString(),
            'periodo'   => $decoded['periodo'] ?? 'mes',
            'parcelas'  => $decoded['parcelas'] ?? 1,
        ];
    }

    // Refatoração 2: Método único de download
    private function downloadTelegramFile(string $fileId, string $prefix, string $extension): string
    {
        $response = Http::get("https://api.telegram.org/bot{$this->botToken}/getFile", ['file_id' => $fileId]);

        if (!$response->successful() || !isset($response['result']['file_path'])) {
            throw new \Exception("Falha ao obter path do arquivo no Telegram.");
        }

        $filePath = $response['result']['file_path'];
        $url = "https://api.telegram.org/file/bot{$this->botToken}/{$filePath}";
        $data = file_get_contents($url);

        $localPath = storage_path("app/temp_{$prefix}_{$fileId}.{$extension}");
        file_put_contents($localPath, $data);

        return $localPath;
    }

    private function transcribeAudio(string $filePath): string
    {
        $apiKey = env('GROQ_API_KEY');
        $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
            ->attach('file', file_get_contents($filePath), 'audio.ogg')
            ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                'model' => 'whisper-large-v3-turbo',
                'response_format' => 'json',
                'language' => 'pt'
            ]);

        if (!$response->successful()) {
            throw new \Exception("Falha na API de transcrição.");
        }

        return $response->json('text');
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
        if (!$budget || $budget->limite <= 0) return;

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

        $continuouEstourado = ($percentualAtual > 100 && $percentualAnterior >= 100);

        if ($cruzou100) {
            $this->sendTelegramMessage($chatId, "🛑 *Limite Estourado! ({$categoria})*\nVocê acabou de ultrapassar sua meta de R$ {$limiteFmt}.\nTotal consumido: R$ {$consumidoFmt} ({$percentualFmt}%).");
        } elseif ($continuouEstourado) {
            $this->sendTelegramMessage($chatId, "🚨 *Atenção Máxima! ({$categoria})*\nVocê já havia estourado a meta e continua gastando!\nTotal consumido: R$ {$consumidoFmt} ({$percentualFmt}% de R$ {$limiteFmt}).");
        } elseif ($cruzou90) {
            $saldoRestante = number_format($budget->limite - $somaAtual, 2, ',', '.');
            $this->sendTelegramMessage($chatId, "⚠️ *Quase lá! ({$categoria})*\nVocê atingiu {$percentualFmt}% da sua meta (R$ {$limiteFmt}).\nFaltam apenas R$ {$saldoRestante} para estourar.");
        } elseif ($cruzou75) {
            $this->sendTelegramMessage($chatId, "⚠️ *Atenção! ({$categoria})*\nVocê já consumiu {$percentualFmt}% da sua meta mensal de R$ {$limiteFmt}.");
        } elseif ($cruzou50) {
            $this->sendTelegramMessage($chatId, "⚠️ *Metade da meta! ({$categoria})*\nVocê chegou a {$percentualFmt}% do limite de R$ {$limiteFmt}.");
        }
    }

    private function undoLastExpense($telegramId, $chatId)
    {
        $lastExpense = Expense::where('telegram_id', $telegramId)->latest('created_at')->first();
        if ($lastExpense) {
            $lastExpense->delete();
            $this->sendTelegramMessage($chatId, "Desfeito! Registro removido.");
        }
    }

    private function generateReport(array $extractedData, $telegramId, $chatId)
    {
        $query = Expense::where('telegram_id', $telegramId);
        $periodo = $extractedData['periodo'] ?? 'mes';
        $startDate = match ($periodo) {
            'semana' => now()->startOfWeek(),
            'ano'    => now()->startOfYear(),
            default  => now()->startOfMonth(),
        };

        $query->where('data', '>=', $startDate->toDateString());
        $despesas = $query->orderBy('data', 'asc')->get();

        $msg = "📊 *Relatório (" . ucfirst($periodo) . ")*\n\n";
        $totalR = 0;
        $totalD = 0;

        foreach ($despesas as $exp) {
            $val = number_format($exp->valor, 2, ',', '.');
            $emoji = ($exp->tipo === 'receita') ? '🟢' : '🔴';
            $msg .= "{$emoji} {$exp->data} - R$ {$val} (" . ($exp->descricao ?? $exp->categoria) . ")\n";
            ($exp->tipo === 'receita') ? $totalR += $exp->valor : $totalD += $exp->valor;
        }

        $msg .= "\n💰 Saldo: R$ " . number_format($totalR - $totalD, 2, ',', '.');
        $this->sendTelegramMessage($chatId, $msg);
    }

    private function isUserAllowed(int $telegramId): bool
    {
        $allowed = env('ALLOWED_TELEGRAM_USERS', '');
        return ($allowed === '*') || in_array((string)$telegramId, explode(',', $allowed));
    }

    private function processViaLangflow(string $text): array
    {
        $baseUrl = rtrim(env('LANGFLOW_URL', 'http://localhost:8000'), '/');
        $currentDate = now()->toIso8601String();
        $flowId = env('LANGFLOW_FLOW_ID');

        $url = "{$baseUrl}/api/v1/run/{$flowId}?stream=false";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(90)
            ->post($url, [
                'input_value' => $text,
                'input_type'  => 'chat',
                'output_type' => 'chat',
                'tweaks'      => ['New Flow' => ['data_atual' => $currentDate]]
            ]);

        if (!$response->successful()) {
            Log::error("Falha no Langflow API. Status: " . $response->status() . " - Resposta: " . $response->body());
            throw new \Exception("Falha Langflow.");
        }

        $aiText = $response->json('outputs.0.outputs.0.results.message.data.text');

        if (!$aiText) {
            Log::error("Langflow retornou uma estrutura de texto vazia.");
            throw new \Exception("A IA não gerou uma resposta válida.");
        }

        $cleanJson = preg_replace('/```json\s?|```\s?/', '', $aiText);
        $decoded = json_decode($cleanJson, true);

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
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $payload);
    }

    private function editTelegramMessage(int $chatId, int $messageId, string $text): void
    {
        Http::post("https://api.telegram.org/bot{$this->botToken}/editMessageText", [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ]);
    }

    private function sendChatAction(int $chatId, string $action = 'typing'): void
    {
        Http::post("https://api.telegram.org/bot{$this->botToken}/sendChatAction", [
            'chat_id' => $chatId,
            'action'  => $action
        ]);
    }
}
