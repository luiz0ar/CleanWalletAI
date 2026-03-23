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
        $message = $request->input('message');
        if (!$message || !isset($message['text'])) {
            return response()->json(['status' => 'ok']);
        }

        $telegramId = $message['from']['id'];
        $chatId = $message['chat']['id'];
        $text = $message['text'];

        if (!$this->isUserAllowed($telegramId)) {
            Log::warning("Tentativa de acesso não autorizada. Telegram ID: {$telegramId}");
            return response()->json(['status' => 'forbidden']);
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
            ]);

            $valorFormatado = number_format($expense->valor, 2, ',', '.');
            $descricaoStr = $expense->descricao ? ucfirst($expense->descricao) : 'Sem descrição';

            $mensagemSucesso = "✅ Registro Salvo\nR$ {$valorFormatado}\n{$descricaoStr}\n{$expense->categoria}";

            $this->sendTelegramMessage($chatId, null, $mensagemSucesso);
        } catch (\Exception $e) {
            Log::error("Erro ao processar mensagem: " . $e->getMessage());
            $this->sendTelegramMessage($chatId, null, "❌ Ocorreu um erro ao processar sua solicitação.");
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Remove o último registro do usuário
     */
    private function undoLastExpense($telegramId, $chatId)
    {
        $lastExpense = Expense::where('telegram_id', $telegramId)->latest('created_at')->first();

        if (!$lastExpense) {
            $this->sendTelegramMessage($chatId, null, "⚠️ Não encontrei nenhum gasto recente para desfazer.");
            return;
        }

        $lastExpense->delete();
        $this->sendTelegramMessage($chatId, null, "Desfeito! O registro de R$ " . number_format($lastExpense->valor, 2, ',', '.') . " em '{$lastExpense->categoria}' foi apagado.");
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

        $descricao = $extractedData['descricao'] ?? null;
        if (!empty($descricao)) {
            $query->where('descricao', 'like', "%{$descricao}%");
        }

        $despesas = $query->orderBy('created_at', 'asc')->get();

        $periodoStr = ucfirst($periodo);
        $filtroStr = "";
        if (!empty($categoria)) $filtroStr .= " em " . ucfirst($categoria);
        if (!empty($descricao)) $filtroStr .= " com " . ucfirst($descricao);

        $mensagem = "📊 *Relatório ({$periodoStr}){$filtroStr}*\n\n";

        if ($despesas->isEmpty()) {
            $mensagem .= "Nenhum gasto encontrado para este filtro.";
        } else {
            $total = 0;

            foreach ($despesas as $exp) {
                $dataRegistro = $exp->data ?? $exp->created_at;
                $dataFmt = \Carbon\Carbon::parse($dataRegistro)->format('d/m');

                $desc = $exp->descricao ? ucfirst($exp->descricao) : ($exp->categoria ? ucfirst($exp->categoria) : 'Outros');
                $val = number_format($exp->valor, 2, ',', '.');

                $mensagem .= "{$dataFmt} - {$desc} - {$exp->categoria} - R$ {$val} \n\n";
                $total += $exp->valor;
            }

            $mensagem .= "\n* 💰Total:* R$ " . number_format($total, 2, ',', '.');
        }

        $this->sendTelegramMessage($chatId, null, $mensagem);
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
