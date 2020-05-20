<?php
ob_end_clean();

class Adapter
{
    private $_db = null;

    public function __construct()
    {
        $db = new mysqli(
            DB_HOST,
            DB_USER,
            DB_PASSWORD,
            DB_NAME
        );
        if ($db->connect_errno) {
            throw new Exception('connection error');
        }
        $this->_db = $db;
    }

    public function __destruct()
    {
        $this->_db->close();
    }

    private function fetchAssoc($query): ?array
    {
        $res = $this->_db->query($query);
        if (!$res) {
            return [];
        }
        return $res->fetch_assoc();
    }

    /**
     * Query service for user
     * @param $userId
     * @param $serviceId
     * @return array|null
     */
    public function getServiceForUser($userId, $serviceId): ?array
    {
        $user = $this->_db->escape_string($userId);
        $service = $this->_db->escape_string($serviceId);
        $query = "select * from services where services.user_id = {$user} and services.id = {$service}";
        return $this->fetchAssoc($query);
    }

    /**
     * Query single tarif by its identifier
     * @param $tarifId
     * @return array|null
     */
    public function getTarifById($tarifId): ?array
    {
        $tarif = $this->_db->escape_string($tarifId);
        $query = "select * from tarifs where tarifs.id = {$tarif}";
        return $this->fetchAssoc($query);
    }

    /**
     * Query all tarifs with group $groupId
     * @param int $groupId - group identifier
     * @return array
     * @throws Exception
     */
    public function getTarifsByGroup(int $groupId): array
    {
        $group = $this->_db->escape_string($groupId);
        $query = "select * from tarifs where tarifs.tarif_group_id = {$group}";
        $res = $this->_db->query($query);
        if (!$res) {
            return [];
        }
        $tarifs = $res->fetch_all(MYSQLI_ASSOC);
        return $this->formatTarifs($tarifs);
    }

    /**
     * Update row in service table
     * @param int $serviceId - service identifier for update
     * @param int $tarifId - new tarif for user
     * @throws Exception
     */
    public function updateUserService(int $serviceId, int $tarifId)
    {
        $tarif = $this->getTarifById($tarifId);
        $payday = $this->buildPayDay($tarif['pay_period']);
        $service = $this->_db->escape_string($serviceId);
        $payday = $this->_db->escape_string($payday->format('Y-m-d'));
        $tarif = $this->_db->escape_string($tarifId);
        $query = "update services set services.payday = '{$payday}', services.tarif_id = {$tarif} where services.id = ${service}";
        $res = $this->_db->query($query);
        if ($res !== true) {
            throw new Exception('Error on query execution');
        }
    }

    /**
     * Reformat DB data for controller
     * @param array $tarifs - array of tarifs rows from db for formatting
     * @return array - formatted list
     * @throws Exception
     */
    private function formatTarifs(array $tarifs): array
    {
        $result = [];
        foreach ($tarifs as $tarif) {
            $date = $this->buildPayDay($tarif['pay_period']);
            $result[] = [
                'ID' => $tarif['ID'],
                'title' => $tarif['title'],
                'price' => (int)$tarif['price'],
                'pay_period' => $tarif['pay_period'],
                'new_payday' => "{$date->getTimestamp()}{$date->format('O')}"
            ];
        }
        return $result;
    }

    /**
     * Create date for next payment
     * @param $payPeriod - payment period in months
     * @return DateTime
     * @throws Exception
     */
    private function buildPayDay($payPeriod)
    {
        $date = new DateTime();
        $date->setTime(0,0,0);
        $day = $date->format('j');
        $date->modify("+{$payPeriod} months");
        if ($date->format('j') !== $day) {
            $date->modify('last day of last month');
        }
        return $date;
    }
}

class Controller
{
    private $adapter = null;

    public function __construct(Adapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Get current user tarif and list of tarifs from same group
     * @param $userId
     * @param $serviceId
     * @throws Exception
     */
    public function getAction($userId, $serviceId): void
    {
        $service = $this->adapter->getServiceForUser($userId, $serviceId);
        if (empty($service)) {
            $this->makeError('Incorrect user or service');
            return;
        }
        $serviceTarif = $this->adapter->getTarifById($service['tarif_id']);
        if (empty($serviceTarif)) {
            $this->makeError('Tarif does not exists');
            return;
        }
        try {
            $formattedTarifs = $this->adapter->getTarifsByGroup($serviceTarif['tarif_group_id']);
        } catch (Exception $exception) {
            $this->makeError($exception->getMessage());
            return;
        }
        $this->makeResponse([
            'tarifs' => [
                'title' => $serviceTarif['title'],
                'link' => $serviceTarif['link'],
                'speed' => $serviceTarif['speed'],
                'tarifs' => $formattedTarifs
            ]
        ]);
    }

    /**
     * Update user service. Set new payday and tarif
     * @param $userId
     * @param $serviceId
     * @param $data
     */
    public function putAction($userId, $serviceId, $data) {
        $service = $this->adapter->getServiceForUser($userId, $serviceId);
        if (empty($service)) {
            $this->makeError('Incorrect service');
            return;
        }
        $tarif = $this->adapter->getTarifById($data['tarif_id']);
        if (empty($tarif)) {
            $this->makeError('Incorrect tarif');
            return;
        }
        try {
            $this->adapter->updateUserService($service['ID'], $tarif['ID']);
        } catch (Exception $exception) {
            $this->makeError($exception->getMessage());
            return;
        }

        $this->makeResponse();
    }

    public function defaultAction(): void {
        $this->makeResponse();
    }

    /**
     * Build and print error response
     * @param string $message - error message for response
     */
    private function makeError(string $message = ''): void {
        $res = ['result' => 'error'];
        if (strlen($message) > 0) {
            $res['message'] = $message;
        }
        $this->makeResponse($res);
        return;
    }

    /**
     * Build and print success response
     * @param array $data
     */
    private function makeResponse(array $data = []): void {
        header('Content-type: application/json');
        echo json_encode(array_merge(['result' => 'ok'], $data));
    }
}

require __DIR__ . '/' . 'db_cfg.php';
try {
    $adapter = new Adapter();
} catch (Exception $exception) {
    header('Content-type: application/json');
    echo json_encode(['result' => 'error', 'message' => $exception->getMessage()]);
}

$controller = new Controller($adapter);

// collect params for defining action
$uri = $_SERVER['REQUEST_URI'];
$matches = [];
preg_match('/\/users\/(?<user_id>\d+)\/services\/(?<service_id>\d+)/', $uri, $matches);

// handle GET /users/{user_id}/services/{service_id}/tarifs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $controller->getAction($matches['user_id'], $matches['service_id']);
    return;
}

// handle PUT /users/{user_id}/services/{service_id}/tarifs
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    $controller->putAction($matches['user_id'], $matches['service_id'], $data);
    return;
}

$controller->defaultAction();