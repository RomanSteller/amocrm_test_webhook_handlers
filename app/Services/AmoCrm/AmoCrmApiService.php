<?php

namespace App\Services\AmoCrm;

use App\Models\AmoCrmToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;


class AmoCrmApiService
{
    private string $subdomain;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->subdomain = config('amo_crm.sub_domain');
        $this->clientId = config('amo_crm.client_id');
        $this->clientSecret = config('amo_crm.client_secret');
        $this->redirectUri = config('amo_crm.redirect_uri');

        if (!$this->subdomain) {
            $msg = 'AMO_CRM_ACCOUNT_SUBDOMAIN не сконфигурирован.';
            Log::critical($msg);
            throw new \Exception($msg);
        }
    }

    private function getToken(): AmoCrmToken
    {
        $token = AmoCrmToken::first();
        if (!$token) {
            $msg = "Токены amoCRM не найдены в базе данных. Необходимо пройти авторизацию.";
            Log::error($msg);
            throw new \Exception($msg);
        }
        return $token;
    }

    private function ensureTokenIsValid(AmoCrmToken $token): AmoCrmToken
    {
        if ($token->expires_at <= now()->addMinutes(5)) {
            Log::info("Обновление токена AmoCRM для {$this->subdomain}");
            $response = Http::post("https://{$this->subdomain}.amocrm.ru/oauth2/access_token", [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $token->refresh_token,
                'redirect_uri'  => $this->redirectUri,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $token->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'expires_at'    => now()->addSeconds($data['expires_in'] - 60), // запас в 60 секунд
                ]);
                Log::info("Токен AmoCRM успешно обновлен для {$this->subdomain}");
            } else {
                $errorMsg = "Ошибка обновления токена AmoCRM: " . $response->body();
                Log::error($errorMsg, ['status' => $response->status(), 'subdomain' => $this->subdomain]);
                throw new \Exception($errorMsg);
            }
        }
        return $token;
    }

    public function fetchData(string $path, string $method = 'get', array $body = []): ?array
    {
        $token = $this->getToken();
        $token = $this->ensureTokenIsValid($token);

        $url = "https://{$this->subdomain}.amocrm.ru{$path}";
        $headers = [
            'Authorization' => 'Bearer ' . $token->access_token,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];

        Log::debug("AmoCRM API Request: {$method} {$url}", ['body_params_count' => count($body)]);

        $response = match (strtolower($method)) {
            'get' => Http::withHeaders($headers)->get($url, $body),
            'post' => Http::withHeaders($headers)->post($url, $body),
            default => throw new \InvalidArgumentException("Неподдерживаемый метод: {$method}"),
        };

        if ($response->successful()) {
            return $response->json();
        } else {
            Log::error("Ошибка API AmoCRM: {$method} {$url}", [
                'status'          => $response->status(),
                'response_body'   => $response->body(),
                'request_body'    => (strtolower($method) !== 'get') ? $body : [],
                'query_params'    => (strtolower($method) === 'get') ? $body : []
            ]);
            throw new \Exception("Ошибка API AmoCRM ({$response->status()}): " . $response->body());
        }
    }

    public function getUser(int $userId): ?array
    {
        try {
            return $this->fetchData("/api/v4/users/{$userId}");
        } catch (\Exception $e) {
            Log::error("Не удалось получить пользователя ID {$userId}: " . $e->getMessage());
            return null;
        }
    }

    public function addNote(string $entityApiType, int $entityId, string $noteText): ?array
    {
        $path = "/api/v4/{$entityApiType}/{$entityId}/notes";
        $requestBody = [
            [
                'note_type' => 'common',
                'params'    => ['text' => $noteText],
            ],
        ];
        try {
            return $this->fetchData($path, 'post', $requestBody);
        } catch (\Exception $e) {
            Log::error("Не удалось добавить примечание для {$entityApiType} ID {$entityId}: " . $e->getMessage());
            return null;
        }
    }
}
