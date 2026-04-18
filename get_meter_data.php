<?php
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$db   = 'company';
$user = 'root';
$pass = '00000000';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    json_response([
        'status' => 'error',
        'message' => '資料庫連線失敗',
        'detail' => $e->getMessage(),
    ], 500);
}

function normalize_type(string $dataType, array $raw, string $time): ?array {
    switch ($dataType) {
        case 'Voltage':
            $va = (float)($raw['Va'] ?? 0);
            $vb = (float)($raw['Vb'] ?? 0);
            $vc = (float)($raw['Vc'] ?? 0);
            return [
                'key' => 'voltage',
                'payload' => [
                    'time' => $time,
                    'Va' => $va,
                    'Vb' => $vb,
                    'Vc' => $vc,
                    'avg' => round(($va + $vb + $vc) / 3, 2),
                ],
            ];
        case 'Current':
            $ia = (float)($raw['Ia'] ?? 0);
            $ib = (float)($raw['Ib'] ?? 0);
            $ic = (float)($raw['Ic'] ?? 0);
            return [
                'key' => 'current',
                'payload' => [
                    'time' => $time,
                    'Ia' => $ia,
                    'Ib' => $ib,
                    'Ic' => $ic,
                    'avg' => round(($ia + $ib + $ic) / 3, 2),
                ],
            ];
        case 'Active_Power':
            $pa = (float)($raw['Pa'] ?? 0);
            $pb = (float)($raw['Pb'] ?? 0);
            $pc = (float)($raw['Pc'] ?? 0);
            return [
                'key' => 'active_power',
                'payload' => [
                    'time' => $time,
                    'Pa' => $pa,
                    'Pb' => $pb,
                    'Pc' => $pc,
                    'total' => round($pa + $pb + $pc, 2),
                ],
            ];
        case 'Reactive_Power':
            $qa = (float)($raw['Qa'] ?? 0);
            $qb = (float)($raw['Qb'] ?? 0);
            $qc = (float)($raw['Qc'] ?? 0);
            return [
                'key' => 'reactive_power',
                'payload' => [
                    'time' => $time,
                    'Qa' => $qa,
                    'Qb' => $qb,
                    'Qc' => $qc,
                    'total' => round($qa + $qb + $qc, 2),
                ],
            ];
        case 'Power_Factor':
            return [
                'key' => 'power_factor',
                'payload' => [
                    'time' => $time,
                    'PF' => (float)($raw['PF'] ?? 0),
                ],
            ];
        case 'Frequency':
            return [
                'key' => 'frequency',
                'payload' => [
                    'time' => $time,
                    'Hz' => (float)($raw['Hz'] ?? 0),
                ],
            ];
        case 'Demand':
            return [
                'key' => 'demand',
                'payload' => [
                    'time' => $time,
                    'DmPt' => (float)($raw['DmPt'] ?? 0),
                ],
            ];
        case 'EPI':
            return [
                'key' => 'epi',
                'payload' => [
                    'time' => $time,
                    'ImpEp' => (float)($raw['ImpEp'] ?? 0),
                ],
            ];
        case 'EPE':
            return [
                'key' => 'epe',
                'payload' => [
                    'time' => $time,
                    'ExpEp' => (float)($raw['ExpEp'] ?? 0),
                ],
            ];
        default:
            return null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonStr = file_get_contents('php://input');
    $data = json_decode($jsonStr, true);

    if (!$data || !isset($data['host_id'], $data['device_id'], $data['time'], $data['data']['type'])) {
        json_response([
            'status' => 'error',
            'message' => '無效的 JSON 資料',
        ], 400);
    }

    $sql = "INSERT INTO meter_data (host_id, device_id, data_time, data_type, raw_data)
            VALUES (:host_id, :device_id, :data_time, :data_type, :raw_data)";

    $stmt = $pdo->prepare($sql);
    $formattedTime = date('Y-m-d H:i:s', strtotime($data['time']));
    $stmt->execute([
        'host_id' => $data['host_id'],
        'device_id' => $data['device_id'],
        'data_time' => $formattedTime,
        'data_type' => $data['data']['type'],
        'raw_data' => json_encode($data['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    json_response([
        'status' => 'success',
        'message' => '資料已寫入',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response([
        'status' => 'error',
        'message' => '不支援的請求方法',
    ], 405);
}

$hostId = $_GET['host_id'] ?? 'cyc-014';
$deviceId = $_GET['device_id'] ?? '01';
$historyLimit = max(5, min((int)($_GET['history_limit'] ?? 24), 200));

$latestSql = "SELECT t1.*
    FROM meter_data t1
    INNER JOIN (
        SELECT data_type, MAX(id) AS max_id
        FROM meter_data
        WHERE host_id = :host_id AND device_id = :device_id
        GROUP BY data_type
    ) t2 ON t1.id = t2.max_id
    ORDER BY t1.id ASC";

$latestStmt = $pdo->prepare($latestSql);
$latestStmt->execute([
    'host_id' => $hostId,
    'device_id' => $deviceId,
]);
$latestRows = $latestStmt->fetchAll();

$historySql = "SELECT *
    FROM meter_data
    WHERE host_id = :host_id AND device_id = :device_id
    ORDER BY id DESC
    LIMIT :limit";
$historyStmt = $pdo->prepare($historySql);
$historyStmt->bindValue(':host_id', $hostId, PDO::PARAM_STR);
$historyStmt->bindValue(':device_id', $deviceId, PDO::PARAM_STR);
$historyStmt->bindValue(':limit', $historyLimit * 12, PDO::PARAM_INT);
$historyStmt->execute();
$historyRows = array_reverse($historyStmt->fetchAll());

$response = [
    'status' => 'success',
    'meta' => [
        'host_id' => $hostId,
        'device_id' => $deviceId,
        'history_limit' => $historyLimit,
    ],
    'latest' => [],
    'history' => [
        'voltage' => [],
        'current' => [],
        'active_power' => [],
        'reactive_power' => [],
        'power_factor' => [],
        'frequency' => [],
        'demand' => [],
    ],
];

foreach ($latestRows as $row) {
    $raw = json_decode($row['raw_data'], true) ?: [];
    $normalized = normalize_type($row['data_type'], $raw, $row['data_time']);
    if ($normalized !== null) {
        $response['latest'][$normalized['key']] = $normalized['payload'];
    }
}

foreach ($historyRows as $row) {
    $raw = json_decode($row['raw_data'], true) ?: [];
    $type = $row['data_type'];
    $time = $row['data_time'];
    switch ($type) {
        case 'Voltage':
            $va = (float)($raw['Va'] ?? 0);
            $vb = (float)($raw['Vb'] ?? 0);
            $vc = (float)($raw['Vc'] ?? 0);
            $response['history']['voltage'][] = [
                'time' => $time,
                'avg' => round(($va + $vb + $vc) / 3, 2),
                'Va' => $va,
                'Vb' => $vb,
                'Vc' => $vc,
            ];
            break;
        case 'Current':
            $ia = (float)($raw['Ia'] ?? 0);
            $ib = (float)($raw['Ib'] ?? 0);
            $ic = (float)($raw['Ic'] ?? 0);
            $response['history']['current'][] = [
                'time' => $time,
                'avg' => round(($ia + $ib + $ic) / 3, 2),
                'Ia' => $ia,
                'Ib' => $ib,
                'Ic' => $ic,
            ];
            break;
        case 'Active_Power':
            $pa = (float)($raw['Pa'] ?? 0);
            $pb = (float)($raw['Pb'] ?? 0);
            $pc = (float)($raw['Pc'] ?? 0);
            $response['history']['active_power'][] = [
                'time' => $time,
                'total' => round($pa + $pb + $pc, 2),
                'Pa' => $pa,
                'Pb' => $pb,
                'Pc' => $pc,
            ];
            break;
        case 'Reactive_Power':
            $qa = (float)($raw['Qa'] ?? 0);
            $qb = (float)($raw['Qb'] ?? 0);
            $qc = (float)($raw['Qc'] ?? 0);
            $response['history']['reactive_power'][] = [
                'time' => $time,
                'total' => round($qa + $qb + $qc, 2),
                'Qa' => $qa,
                'Qb' => $qb,
                'Qc' => $qc,
            ];
            break;
        case 'Power_Factor':
            $response['history']['power_factor'][] = [
                'time' => $time,
                'PF' => (float)($raw['PF'] ?? 0),
            ];
            break;
        case 'Frequency':
            $response['history']['frequency'][] = [
                'time' => $time,
                'Hz' => (float)($raw['Hz'] ?? 0),
            ];
            break;
        case 'Demand':
            $response['history']['demand'][] = [
                'time' => $time,
                'DmPt' => (float)($raw['DmPt'] ?? 0),
            ];
            break;
    }
}

foreach ($response['history'] as $key => $series) {
    if (count($series) > $historyLimit) {
        $response['history'][$key] = array_slice($series, -$historyLimit);
    }
}

json_response($response);
