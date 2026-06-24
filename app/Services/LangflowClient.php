<?php

namespace App\Services;

use GuzzleHttp\Client;

class LangflowClient
{
    protected $baseUrl;

    protected $http;

    public function __construct()
    {
        $this->baseUrl = config('services.langflow.url');
        $this->http = new Client(['base_uri' => $this->baseUrl]);
    }

    public function parseMessage(string $text, string $now): array
    {
        $resp = $this->http->post('/parse', [
            'json' => [
                'text' => $text,
                'now' => $now,
            ],
        ]);

        $body = json_decode((string) $resp->getBody(), true);

        return [
            'valor' => $body['valor'] ?? 0,
            'categoria' => $body['categoria'] ?? 'Outros',
            'descricao' => $body['descricao'] ?? $text,
            'data' => $body['data'] ?? $now,
        ];
    }
}
