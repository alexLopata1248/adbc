<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @var Contractor
     */
    protected Contractor $client;

    protected $resellerId;

    /**
     * @var int
     */
    protected int $notificationType;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        return $this->notifcate();
    }

    /**
     * @return array
     * @throws Exception
     */
    private function notifcate(): array
    {
        try {
            $data = (array)$this->getRequest('data');

            $data = $this->securityCheck($data);

            $result = [
                'notificationEmployeeByEmail' => false,
                'notificationClientByEmail' => false,
                'notificationClientBySms' => [
                    'isSent' => false,
                    'message' => '',
                ],
            ];

            $templateData = $this->getTemplate($data);

            if (!$templateData) {
                $result['notificationClientBySms']['message'] = 'Empty resellerId';
            }

            $emailFrom = getResellerEmailFrom();
            $result = $this->notificateEmployee($emailFrom, $templateData, $result);

            $result = $this->notificateClient($data, $emailFrom, $templateData, $result);

            return $result;
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @param array $data
     * @return array
     */
    private function securityCheck(array $data): array
    {
        //так данные приходят с фронта, ожидать там можно - что угодно. Поэтому лучше защититься от SQL-инъекций и XSS-инъекций
        foreach ($data as $key => $value) {
            //защита от SQL-injection
            //...использование безопасных запросов...$pdo->prepare....или 'mysqli_execute_query' или.....

            //чтобы защититься от XSS-injection
            $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            $data[$key] = strip_tags($value);
        }

        return $data;
    }

    /**
     * @param array $data
     * @return array
     * @throws Exception
     */
    private function getTemplate(array $data): array
    {
        $resellerId = $data['resellerId'];
        $notificationType = (int)$data['notificationType'];
        $creatorId = (int)$data['creatorId'];
        $expertId = (int)$data['expertId'];
        $clientId = (int)$data['clientId'];

        if (empty((int)$resellerId)) {
            return [];
        }

        if (empty((int)$notificationType)) {
            throw new Exception('Empty notificationType', 400);
        }

        $reseller = Seller::getById((int)$resellerId);
        if ($reseller === null) {
            throw new Exception('Seller not found!', 400);
        }

        $this->client = Contractor::getById($clientId);
        $client = $this->client;
        if ($client === null || $client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== $resellerId) {
            throw new Exception('сlient not found!', 400);
        }

        $cFullName = $client->getFullName();
        if (empty($client->getFullName())) {
            $cFullName = $client->name;
        }

        $cr = Employee::getById($creatorId);
        if ($cr === null) {
            throw new Exception('Creator not found!', 400);
        }

        $et = Employee::getById($expertId);
        if ($et === null) {
            throw new Exception('Expert not found!', 400);
        }

        $differences = '';
        if ($notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $resellerId);
        } elseif ($notificationType === self::TYPE_CHANGE && !empty($data['differences'])) {
            $differences = __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO'   => Status::getName((int)$data['differences']['to']),
            ], $resellerId);
        }

        $templateData = [
            'COMPLAINT_ID'       => (int)$data['complaintId'],
            'COMPLAINT_NUMBER'   => (string)$data['complaintNumber'],
            'CREATOR_ID'         => $creatorId,
            'CREATOR_NAME'       => $cr->getFullName(),
            'EXPERT_ID'          => $expertId,
            'EXPERT_NAME'        => $et->getFullName(),
            'CLIENT_ID'          => $clientId,
            'CLIENT_NAME'        => $cFullName,
            'CONSUMPTION_ID'     => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER'   => (string)$data['agreementNumber'],
            'DATE'               => (string)$data['date'],
            'DIFFERENCES'        => $differences,
        ];

        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        return $templateData;
    }

    /**
     * @param string $emailFrom
     * @param array $templateData
     * @param array $result
     * @return array
     */
    private function notificateEmployee(string $emailFrom, array $templateData, array $result): array
    {
        $resellerId = $this->resellerId;

        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $email,
                        'subject'   => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        return $result;
    }

    /**
     * @param string $emailFrom
     * @param array $templateData
     * @param array $result
     * @return array
     */
    private function notificateClient(array $data, string $emailFrom, array $templateData, array $result): array
    {
        $notificationType = $this->notificationType;
        $resellerId = $this->resellerId;
        $error = '';
        $differencesTo = $data['differences']['to'] ?? null;

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($notificationType === self::TYPE_CHANGE && $differencesTo) {
            $differencesTo = (int) $differencesTo;
            //@TODO по-хорошему, конечно, надо разбить на 2 функции notificateByEmail и notificateBySms, но чё-то не соображу, как это тут лучше будет сделать...
            if (!empty($emailFrom) && !empty($client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                        'emailFrom' => $emailFrom,
                        'emailTo'   => $client->email,
                        'subject'   => __('complaintClientEmailSubject', $templateData, $resellerId),
                        'message'   => __('complaintClientEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differencesTo);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($client->mobile)) {
                $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, $differencesTo, $templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }
}
