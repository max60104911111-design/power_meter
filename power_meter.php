<?php
// 資料庫連線設定
$host = 'localhost';
$db   = 'power_meter';
$user = 'root';
$pass = 'ROOT_pwd_123';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

// 接收來自 Python 的 JSON 資料
$json_str = file_get_contents('php://input');
$data = json_decode($json_str, true);

if ($data) {
    $sql = "INSERT INTO meter_data (host_id, device_id, data_time, data_type, raw_data) 
            VALUES (?, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    
    // 轉換時間格式 (假設截圖中的 2026/3/27)
    $formatted_time = date('Y-m-d H:i:s', strtotime($data['time']));
    
    $stmt->execute([
        $data['host_id'],
        $data['device_id'],
        $formatted_time,
        $data['data']['type'],
        json_encode($data['data']) // 將內部 data 物件轉為 JSON 字串存入
    ]);

    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => "無效的資料"]);
}
?>
