<?php

namespace App\Services\AmoCrm;

use App\Models\AmoCrmActionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class AmoCrmWebhookService
{
    private AmoCrmApiService $apiService;

    public function __construct(AmoCrmApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * @param array $leadData
     * @return void
     */
    public function handleLeadAdded(array $leadData): void
    {
        $leadId = $leadData['id'];
        Cache::put("lead_{$leadId}", $leadData, now()->addHours(24));
        $this->logDbAction('lead', $leadId, 'added', null, $leadData);

        $leadName = $leadData['name'] ?? 'Без названия';
        $responsibleUserId = $leadData['responsible_user_id'] ?? null;
        $createdAt = Carbon::createFromTimestamp($leadData['created_at'])->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s');

        $responsibleUserName = 'Неизвестно';
        if ($responsibleUserId) {
            $user = $this->apiService->getUser((int)$responsibleUserId);
            $responsibleUserName = $user['name'] ?? "ID: {$responsibleUserId}";
        }

        $noteText = "Создана сделка: {$leadName}\nОтветственный: {$responsibleUserName}\nВремя создания: {$createdAt}";
        $this->apiService->addNote('leads', $leadId, $noteText);
    }

    /**
     * @param array $contactData
     * @return void
     */
    public function handleContactAdded(array $contactData): void
    {
        $contactId = $contactData['id'];
        Cache::put("contact_{$contactId}", $contactData, now()->addHours(24));
        $this->logDbAction('contact', $contactId, 'added', null, $contactData);

        $contactName = $contactData['name'] ?? 'Без имени';
        $responsibleUserId = $contactData['responsible_user_id'] ?? null;
        $createdAtTimestamp = is_numeric($contactData['created_at']) ? $contactData['created_at'] : Carbon::parse($contactData['created_at'])->timestamp;
        $createdAt = Carbon::createFromTimestamp($createdAtTimestamp)->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s');

        $responsibleUserName = 'Неизвестно';
        if ($responsibleUserId) {
            $user = $this->apiService->getUser((int)$responsibleUserId);
            $responsibleUserName = $user['name'] ?? "ID: {$responsibleUserId}";
        }

        $noteText = "Создан контакт: {$contactName}\nОтветственный: {$responsibleUserName}\nВремя создания: {$createdAt}";
        $this->apiService->addNote('contacts', $contactId, $noteText);
    }

    /**
     * @param array $leadData
     * @return void
     */
    public function handleLeadUpdated(array $leadData): void
    {
        $leadId = $leadData['id'];
        $previousLead = Cache::get("lead_{$leadId}");
        $changes = $this->extractChangedValues($previousLead, $leadData, 'leads');

        Cache::put("lead_{$leadId}", $leadData, now()->addHours(24)); // Обновляем кэш в любом случае
        $this->logDbAction('lead', $leadId, 'updated', $changes, $leadData);

        $now = Carbon::now()->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s');
        $noteText = "";

        if (!$previousLead) {
            Log::info("Обновление сделки ID {$leadId} без предыдущих данных в кэше.");
            $responsibleUserName = 'Неизвестно';
            if (isset($leadData['responsible_user_id'])) {
                $user = $this->apiService->getUser((int)$leadData['responsible_user_id']);
                $responsibleUserName = $user['name'] ?? "ID: {$leadData['responsible_user_id']}";
            }
            $leadName = $leadData['name'] ?? 'Без названия';
            $noteText = "Сделка ID {$leadId} была изменена (предыдущее состояние не закэшировано).\n";
            $noteText .= "Текущее название: {$leadName}\nТекущий ответственный: {$responsibleUserName}\n";
            if ($changes) {
                $noteText .= "Обнаруженные изменения (относительно пустого состояния):\n";
                foreach ($changes as $field => $value) {
                    $noteText .= "Поле '{$field}': стало '{$value['new']}'\n";
                }
            }
        } elseif ($changes) {
            $updatedFieldsStrings = [];
            foreach ($changes as $field => $value) {
                $oldValDisplay = $value['old'] === null ? 'не было задано' : $value['old'];
                $updatedFieldsStrings[] = "Поле '{$field}': было '{$oldValDisplay}' -> стало '{$value['new']}'";
            }
            if (!empty($updatedFieldsStrings)) {
                $noteText = "Изменения в сделке:\n" . implode("\n", $updatedFieldsStrings);
            } else {
                Log::info("Получен хук на обновление сделки ID {$leadId}, но отслеживаемых изменений не найдено по сравнению с кэшем.");
                $noteText = "Сделка была обновлена (без изменений отслеживаемых полей)."; // Или не создавать заметку
            }
        } else {
            Log::info("Получен хук на обновление сделки ID {$leadId}, но изменений не найдено (данные идентичны кэшу).");
            $noteText = "Сделка была обновлена (без изменений отслеживаемых полей)."; // Или не создавать заметку
        }

        if ($noteText) {
            $noteText .= "\nВремя изменения: {$now}";
            $this->apiService->addNote('leads', $leadId, $noteText);
        }
    }

    /**
     * @param array $contactData
     * @return void
     */
    public function handleContactUpdated(array $contactData): void
    {
        $contactId = $contactData['id'];
        $previousContact = Cache::get("contact_{$contactId}");
        $changes = $this->extractChangedValues($previousContact, $contactData, 'contacts');

        Cache::put("contact_{$contactId}", $contactData, now()->addHours(24));
        $this->logDbAction('contact', $contactId, 'updated', $changes, $contactData);

        $now = Carbon::now()->setTimezone('Europe/Moscow')->format('d.m.Y H:i:s');
        $noteText = "";

        if (!$previousContact) {
            Log::info("Обновление контакта ID {$contactId} без предыдущих данных в кэше.");
            $responsibleUserName = 'Неизвестно';
            if (isset($contactData['responsible_user_id'])) {
                $user = $this->apiService->getUser((int)$contactData['responsible_user_id']);
                $responsibleUserName = $user['name'] ?? "ID: {$contactData['responsible_user_id']}";
            }
            $contactName = $contactData['name'] ?? 'Без имени';
            $noteText = "Контакт ID {$contactId} был изменен (предыдущее состояние не закэшировано).\n";
            $noteText .= "Текущее имя: {$contactName}\nТекущий ответственный: {$responsibleUserName}\n";
            if ($changes) {
                $noteText .= "Обнаруженные изменения (относительно пустого состояния):\n";
                foreach ($changes as $field => $value) {
                    $noteText .= "Поле '{$field}': стало '{$value['new']}'\n";
                }
            }
        } elseif ($changes) {
            $updatedFieldsStrings = [];
            foreach ($changes as $field => $value) {
                $oldValDisplay = $value['old'] === null ? 'не было задано' : $value['old'];
                $updatedFieldsStrings[] = "Поле '{$field}': было '{$oldValDisplay}' -> стало '{$value['new']}'";
            }
            if (!empty($updatedFieldsStrings)) {
                $noteText = "Изменения в контакте:\n" . implode("\n", $updatedFieldsStrings);
            } else {
                Log::info("Получен хук на обновление контакта ID {$contactId}, но отслеживаемых изменений не найдено по сравнению с кэшем.");
                $noteText = "Контакт был обновлен (без изменений отслеживаемых полей).";
            }
        } else {
            Log::info("Получен хук на обновление контакта ID {$contactId}, но изменений не найдено (данные идентичны кэшу).");
            $noteText = "Контакт был обновлен (без изменений отслеживаемых полей).";
        }

        if ($noteText) {
            $noteText .= "\nВремя изменения: {$now}";
            $this->apiService->addNote('contacts', $contactId, $noteText);
        }
    }

    /**
     * @param string $entityType
     * @param int $entityId
     * @param string $actionType
     * @param array|null $changedValues
     * @param array|null $currentEntityState
     * @return void
     */
    private function logDbAction(string $entityType, int $entityId, string $actionType, ?array $changedValues, ?array $currentEntityState): void
    {
        AmoCrmActionLog::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action_type' => $actionType,
            'old_values' => $changedValues ? json_encode($changedValues) : null,
            'new_values' => $currentEntityState ? json_encode($currentEntityState) : null,
        ]);
    }

    /**
     * Сделано сравнение для имени и ответственного
     *
     * @param array|null $oldEntity
     * @param array|null $newEntity
     * @param string $entityTypeContext
     * @return array|null
     */
    public function extractChangedValues(?array $oldEntity, ?array $newEntity, string $entityTypeContext): ?array
    {
        if (!$newEntity) {
            return null;
        }

        $changedValues = [];
        $isInitialOrNoOldData = !$oldEntity;

        $formatDisplayValue = function ($value) {
            return ($value === null || $value === '' || (is_array($value) && empty($value))) ? 'не задано' : $value;
        };

        // 1. Имя
        $oldName = $oldEntity['name'] ?? null;
        $newName = $newEntity['name'] ?? null;
        if ($isInitialOrNoOldData || $oldName !== $newName) {
            $changedValues['Название'] = [
                'old' => $isInitialOrNoOldData ? null : $formatDisplayValue($oldName),
                'new' => $formatDisplayValue($newName)
            ];
        }

        // 2. Ответственный
        $oldResponsibleId = $oldEntity['responsible_user_id'] ?? null;
        $newResponsibleId = $newEntity['responsible_user_id'] ?? null;

        if ($newResponsibleId && ($isInitialOrNoOldData || $oldResponsibleId !== $newResponsibleId)) {
            $oldUserName = null;
            if ($oldResponsibleId) {
                $oldUserData = $this->apiService->getUser((int)$oldResponsibleId);
                $oldUserName = $oldUserData['name'] ?? "ID: {$oldResponsibleId}";
            }
            $newUserData = $this->apiService->getUser((int)$newResponsibleId);
            $newUserName = $newUserData['name'] ?? "ID: {$newResponsibleId}";
            $changedValues['Ответственный'] = [
                'old' => $isInitialOrNoOldData ? null : $formatDisplayValue($oldUserName),
                'new' => $formatDisplayValue($newUserName)
            ];
        }


        return !empty($changedValues) ? $changedValues : null;
    }
}
