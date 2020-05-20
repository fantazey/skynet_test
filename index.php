<?php
ob_end_clean();

class Adapter {
    private $_db = null;

    public function __construct() {
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
        mysqli_close($this->_db);
    }

    private function fetchAssoc($query) {
        $res = $this->_db->query($query);
        if (!$res) {
            return [];
        }
        return $res->fetch_assoc();
    }

    public function getServiceForUser($userId, $serviceId) {
        $user = $this->_db->escape_string($userId);
        $service = $this->_db->escape_string($serviceId);
        $query = "select * from services where services.user_id = {$user} and services.id = {$service}";
        return $this->fetchAssoc($query);
    }

    public function getTarifById($tarifId) {
        $tarif = $this->_db->escape_string($tarifId);
        $query = "select * from tarifs where tarifs.id = {$tarif}";
        return $this->fetchAssoc($query);
    }

    public function getTarifsByGroup($groupId) {
        $group = $this->_db->escape_string($groupId);
        $query = "select * from tarifs where tarifs.tarif_group_id = {$group}";
        $res = $this->_db->query($query);
        if (!$res) {
            return [];
        }
        return $res->fetch_all(MYSQLI_ASSOC);
    }

    public function updateUserService($serviceId, $data) {
        $service = $this->_db->escape_string($serviceId);
        $payday = $this->_db->escape_string($data['payday']->format('Y-m-d'));
        $tarif = $this->_db->escape_string($data['tarif_id']);
        $query = "update services set services.payday = '{$payday}', services.tarif_id = {$tarif} where services.id = ${service}";
        return $this->_db->query($query);
    }
}

class Controller {
    private $adapter = null;

    public function __construct(Adapter $adapter) {
        $this->adapter = $adapter;
    }

    public function getAction($userId, $serviceId): void {
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
        $tarifsFromGroup = $this->adapter->getTarifsByGroup($serviceTarif['tarif_group_id']);
        $formattedTarifs = $this->formatTarifs($tarifsFromGroup);
        $this->makeResponse([
            'tarifs' => [
                'title' => $serviceTarif['title'],
                'link' => $serviceTarif['link'],
                'speed' => $serviceTarif['speed'],
                'tarifs' => $formattedTarifs
            ]
        ]);
    }

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
        $updateData = [
            'tarif_id' => $tarif['ID'],
            'payday' => $this->buildPayDay($tarif['pay_period'])
        ];
        $result = $this->adapter->updateUserService($service['ID'], $updateData);
        if ($result !== true) {
            $this->makeError('Error on updating service');
            return;
        }
        $this->makeResponse();
    }

    public function defaultAction(): void {
        $this->makeError();
    }

    private function buildPayDay($payPeriod) {
        $date = new DateTime();
        $date->setTime(0,0,0);
        $day = $date->format('j');
        $date->modify("+{$payPeriod} months");
        if ($date->format('j') !== $day) {
            $date->modify('last day of last month');
        }
        return $date;
    }

    private function formatTarifs(array $tarifs): array {
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

    private function makeError(string $message = '') {
        $res = ['result' => 'error'];
        if (strlen($message) > 0) {
            $res['message'] = $message;
        }
        $this->makeResponse($res);
    }

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
$uri = $_SERVER['REQUEST_URI'];
$matches = [];
preg_match('/\/users\/(?<user_id>\d+)\/services\/(?<service_id>\d+)/', $uri, $matches);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    return $controller->getAction($matches['user_id'], $matches['service_id']);
} else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    return $controller->putAction($matches['user_id'], $matches['service_id'], $data);
}
return $controller->defaultAction();