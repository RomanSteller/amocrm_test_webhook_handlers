<?php
// app/Http/Controllers/WebhookController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\AmoCrm\AmoCrmWebhookService;

class WebhookController extends Controller
{
    private AmoCrmWebhookService $webhookService;

    public function __construct(AmoCrmWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    public function handle(Request $request)
    {
        try {
            $payload = $request->all();


            if (isset($payload['leads']['add'][0])) {
                $this->webhookService->handleLeadAdded($payload['leads']['add'][0]);
            } elseif (isset($payload['contacts']['add'][0])) {
                $this->webhookService->handleContactAdded($payload['contacts']['add'][0]);
            } elseif (isset($payload['leads']['update'][0])) {
                $this->webhookService->handleLeadUpdated($payload['leads']['update'][0]);
            } elseif (isset($payload['contacts']['update'][0])) {
                $this->webhookService->handleContactUpdated($payload['contacts']['update'][0]);
            } else {
                Log::info('сущность нераспознана');
            }

            return response('Webhook обработан', 200);
        } catch (\Throwable $e) { // Ловим Throwable для более широкого охвата ошибок
            Log::error('Критическая ошибка обработки вебхука AmoCRM: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'payload' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response('Ошибка обработки вебхука', 500);
        }
    }
}
