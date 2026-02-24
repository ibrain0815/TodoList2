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

// DB 연결 정보 (config.php에서 로드)
$config = require __DIR__ . '/config.php';
$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

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

// [초기화] 테이블 및 인덱스 설정 (기존 데이터 유지, sort_order 컬럼만 추가)
if ($method === 'GET' && $action === 'init') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS todos (
            id BIGINT PRIMARY KEY,
            todo_text VARCHAR(500) NOT NULL,
            completed TINYINT(1) DEFAULT 0,
            todo_date DATE NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_todo_date (todo_date),
            INDEX idx_sort (todo_date, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 이미 존재하는 테이블에는 sort_order 컬럼만 추가 (데이터 삭제 없음)
        try {
            $pdo->exec("ALTER TABLE todos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER todo_date");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false)
                throw $e;
        }
        sendResponse(true, null, '테이블이 성공적으로 확인되었습니다.');
    } catch (PDOException $e) {
        sendResponse(false, null, '초기화 실패: ' . $e->getMessage());
    }
}

// 1. 할 일 가져오기 (저장된 순서대로, sort_order 없으면 created_at 기준)
if ($method === 'GET' && $action === 'get') {
    if (empty($date))
        $date = date('Y-m-d');
    $orderBy = 'ORDER BY sort_order ASC, created_at ASC, id ASC';
    try {
        $stmt = $pdo->prepare("SELECT id, todo_text as text, CAST(completed AS UNSIGNED) as completed FROM todos WHERE todo_date = ? $orderBy");
        $stmt->execute([$date]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'sort_order') !== false) {
            $stmt = $pdo->prepare("SELECT id, todo_text as text, CAST(completed AS UNSIGNED) as completed FROM todos WHERE todo_date = ? ORDER BY created_at ASC, id ASC");
            $stmt->execute([$date]);
        } else {
            throw $e;
        }
    }
    $todos = $stmt->fetchAll();
    foreach ($todos as &$todo) {
        $todo['completed'] = (bool) $todo['completed'];
    }
    sendResponse(true, $todos);
}

// 2. 전체 저장하기 (배열 순서를 sort_order로 저장 → 위치 고정)
if ($method === 'POST' && $action === 'save') {
    $saveDate = !empty($date) ? $date : date('Y-m-d');
    $todos = $input['todos'] ?? [];

    // 프론트에서 오는 텍스트 필드: text 외에 content, todo_text, label 등도 허용
    $getText = function ($t) {
        $s = $t['text'] ?? $t['content'] ?? $t['todo_text'] ?? $t['label'] ?? '';
        return trim((string) $s);
    };
    $valid = [];
    foreach ($todos as $t) {
        $text = $getText($t);
        if ($text === '') continue;
        $valid[] = [
            'id' => $t['id'] ?? null,
            'text' => $text,
            'completed' => !empty($t['completed']),
        ];
    }
    // id가 없으면 새로 부여 (타임스탬프 기반)
    foreach ($valid as $i => &$v) {
        $id = $v['id'];
        if ($id === null || $id === '' || $id === 0 || (is_string($id) && strtolower($id) === 'undefined')) {
            $v['id'] = (int) (microtime(true) * 1000) + $i;
        }
    }
    unset($v);

    // 저장할 항목이 하나도 없으면 기존 데이터를 지우지 않고, DB 기준 현재 목록을 그대로 반환 (목록 사라짐 방지)
    if (empty($valid)) {
        $stmt = $pdo->prepare("SELECT id, todo_text as text, CAST(completed AS UNSIGNED) as completed FROM todos WHERE todo_date = ? ORDER BY sort_order ASC, created_at ASC, id ASC");
        try {
            $stmt->execute([$saveDate]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'sort_order') !== false) {
                $stmt = $pdo->prepare("SELECT id, todo_text as text, CAST(completed AS UNSIGNED) as completed FROM todos WHERE todo_date = ? ORDER BY created_at ASC, id ASC");
                $stmt->execute([$saveDate]);
            } else {
                throw $e;
            }
        }
        $current = $stmt->fetchAll();
        foreach ($current as &$c) {
            $c['completed'] = (bool) $c['completed'];
        }
        sendResponse(true, $current, '저장할 할 일이 없습니다.');
        return;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM todos WHERE todo_date = ?");
        $stmt->execute([$saveDate]);

        // sort_order로 순서 저장. 컬럼 없으면 트랜잭션 밖에서 컬럼 추가 후 다시 시도 (위치 고정)
        try {
            $stmt = $pdo->prepare("INSERT INTO todos (id, todo_text, completed, todo_date, sort_order) VALUES (?, ?, ?, ?, ?)");
            foreach ($valid as $index => $t) {
                $stmt->execute([$t['id'], $t['text'], $t['completed'] ? 1 : 0, $saveDate, $index]);
            }
        } catch (PDOException $e) {
            $needSort = (strpos($e->getMessage(), 'sort_order') !== false || strpos($e->getMessage(), 'Unknown column') !== false);
            if ($needSort) {
                $pdo->rollBack();
                try {
                    $pdo->exec("ALTER TABLE todos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER todo_date");
                } catch (PDOException $ignored) {}
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("DELETE FROM todos WHERE todo_date = ?");
                $stmt->execute([$saveDate]);
                $stmt = $pdo->prepare("INSERT INTO todos (id, todo_text, completed, todo_date, sort_order) VALUES (?, ?, ?, ?, ?)");
                foreach ($valid as $index => $t) {
                    $stmt->execute([$t['id'], $t['text'], $t['completed'] ? 1 : 0, $saveDate, $index]);
                }
            } else {
                throw $e;
            }
        }

        $pdo->commit();
        $out = array_map(function ($t) {
            return ['id' => $t['id'], 'text' => $t['text'], 'completed' => $t['completed']];
        }, $valid);
        sendResponse(true, $out, '데이터베이스에 저장되었습니다.');
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
