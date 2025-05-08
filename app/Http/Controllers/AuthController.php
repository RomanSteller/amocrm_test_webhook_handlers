<?php

namespace App\Http\Controllers;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Exceptions\AmoCRMApiException;
use App\Models\AmoCrmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    public function authorize()
    {
        $apiClient = new AmoCRMApiClient(
            config('amo_crm.client_id'),
            config('amo_crm.client_secret'),
            config('amo_crm.redirect_uri')
        );

        $authorizationUrl = $apiClient->getOAuthClient()->getAuthorizeUrl([
            'state' => 'your_random_state',
        ]);

        return redirect($authorizationUrl);
    }

    public function callback(Request $request)
    {
        $apiClient = new AmoCRMApiClient(
            config('amo_crm.client_id'),
            config('amo_crm.client_secret'),
            config('amo_crm.redirect_uri')
        );

        $apiClient->setAccountBaseDomain(config('amo_crm.sub_domain') . '.amocrm.ru');

        $code = $request->input('code');
        $state = $request->input('state');

        if (empty($code)) {
            dd('Авторизация не удалась: не получен код.');
        }

        try {
            $accessToken = $apiClient->getOAuthClient()->getAccessTokenByCode($code);

            \App\Models\AmoCrmToken::updateOrCreate(
                [],
                [
                    'access_token' => $accessToken->getToken(),
                    'refresh_token' => $accessToken->getRefreshToken(),
                    'expires_at' => Carbon::createFromTimestamp($accessToken->getExpires()),
                ]
            );

            return 'Access и Refresh токены успешно получены и сохранены!';

        } catch (AmoCRMApiException $e) {
            dd('Ошибка при получении токенов: ' . $e->getMessage());
        }
    }
}
