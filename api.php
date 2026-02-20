<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function sendResponse($success, $data = null, $message = '')
{
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// DB 연결 정보
$db_host = 'localhost';
$db_name = 'soullatte';
$db_user = 'soullatte';
$db_pass = 'healingtime76!';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    sendResponse(false, null, 'DB 연결 실패: ' . $e->getMessage());
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$date = $_GET['date'] ?? $input['date'] ?? '';

// [초기화] 테이블 및 인덱스 설정
if ($method === 'GET' && $action === 'init') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS todos (
            id BIGINT PRIMARY KEY,
            todo_text VARCHAR(500) NOT NULL,
            completed TINYINT(1) DEFAULT 0,
            todo_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_todo_date (todo_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        sendResponse(true, null, '테이브이 성공적으로 확인되었습니다.');
    } catch (PDOException $e) {
        sendResponse(false, null, '초기화 실패: ' . $e->getMessage());
    }
}

// 1. 할 일 가져오기
if ($method === 'GET' && $action === 'get') {
    if (empty($date))
        $date = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT id, todo_text as text, CAST(completed AS UNSIGNED) as completed FROM todos WHERE todo_date = ? ORDER BY created_at ASC");
    $stmt->execute([$date]);
    $todos = $stmt->fetchAll();
    foreach ($todos as &$todo) {
        $todo['completed'] = (bool) $todo['completed'];
    }
    sendResponse(true, $todos);
}

// 2. 전체 저장하기 (가장 확실한 덮어쓰기 방식)
if ($method === 'POST' && $action === 'save') {
    $saveDate = !empty($date) ? $date : date('Y-m-d');
    $todos = $input['todos'] ?? [];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM todos WHERE todo_date = ?");
        $stmt->execute([$saveDate]);
        if (!empty($todos)) {
            $stmt = $pdo->prepare("INSERT INTO todos (id, todo_text, completed, todo_date) VALUES (?, ?, ?, ?)");
            foreach ($todos as $t) {
                if (empty($t['text']))
                    continue;
                $stmt->execute([$t['id'], $t['text'], (!empty($t['completed'])) ? 1 : 0, $saveDate]);
            }
        }
        $pdo->commit();
        sendResponse(true, $todos, '데이터베이스에 저장되었습니다.');
    } catch (Exception $e) {
        $pdo->rollBack();
        sendResponse(false, null, '저장 오류: ' . $e->getMessage());
    }
}

// 3. 자주/최근 사용하는 할 일 목록 가져오기 (전체 데이터 기반 재사용)
if ($method === 'GET' && $action === 'getRecent') {
    $stmt = $pdo->query("
        SELECT todo_text 
        FROM todos 
        GROUP BY todo_text 
        ORDER BY COUNT(*) DESC, MAX(created_at) DESC 
        LIMIT 100
    ");
    $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);
    sendResponse(true, $recent ?: []);
}

// 4. 캘린더 카운트
if ($method === 'GET' && $action === 'getCounts') {
    $month = $_GET['month'] ?? date('Y-m');
    $stmt = $pdo->prepare("SELECT todo_date, COUNT(*) as count FROM todos WHERE todo_date LIKE ? GROUP BY todo_date");
    $stmt->execute([$month . '-%']);
    $results = $stmt->fetchAll();
    $counts = [];
    foreach ($results as $row) {
        $counts[$row['todo_date']] = (int) $row['count'];
    }
    sendResponse(true, $counts);
}

sendResponse(false, null, '잘못된 요청입니다.');
