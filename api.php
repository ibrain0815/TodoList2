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

/**
 * Claude API 키를 환경변수 또는 .env 파일에서 읽어옵니다.
 * - 우선순위: 환경변수 CLAUDE_API_KEY > .env 파일 내 CLAUDE_API_KEY 또는 'claude api key' 라인
 */
function getClaudeApiKey(): string
{
    $envKey = getenv('CLAUDE_API_KEY');
    if (!empty($envKey)) {
        return $envKey;
    }

    $envPath = __DIR__ . '/.env';
    if (!file_exists($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        if (stripos($trim, 'claude') === false) {
            continue;
        }
        $parts = explode('=', $trim, 2);
        if (count($parts) === 2) {
            return trim($parts[1]);
        }
    }

    return '';
}

/**
 * Claude 모델 이름을 환경변수 또는 .env 파일에서 읽어옵니다.
 * - 우선순위: 환경변수 CLAUDE_MODEL > .env 파일 내 CLAUDE_MODEL
 * - 기본값: claude-3-haiku-20240307 (대부분의 계정에서 사용 가능)
 */
function getClaudeModel(): string
{
    $envModel = getenv('CLAUDE_MODEL');
    if (!empty($envModel)) {
        return trim($envModel);
    }

    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || $trim[0] === '#') continue;
            if (stripos($trim, 'CLAUDE_MODEL') === 0 && strpos($trim, '=') !== false) {
                $parts = explode('=', $trim, 2);
                if (count($parts) === 2) {
                    return trim($parts[1]);
                }
            }
        }
    }

    // 기본값: 대부분 계정에서 허용되는 Haiku 3 모델
    return 'claude-3-haiku-20240307';
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

// Claude API 키 설정 여부 확인 (키 값은 반환하지 않음)
if (($method === 'GET' || $method === 'POST') && $action === 'checkClaudeKey') {
    $configured = getClaudeApiKey() !== '';
    sendResponse(true, ['configured' => $configured], $configured ? '연결됨' : '미연결');
}

// Claude API 키 저장 (.env 업데이트)
if ($method === 'POST' && $action === 'saveClaudeKey') {
    $apiKey = isset($input['apiKey']) ? trim((string)$input['apiKey']) : '';
    if ($apiKey === '') {
        sendResponse(false, null, 'API 키가 비어 있습니다.');
    }

    $envPath = __DIR__ . '/.env';
    $lines = file_exists($envPath) ? file($envPath, FILE_IGNORE_NEW_LINES) : [];
    $found = false;

    foreach ($lines as &$line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            continue;
        }
        if (stripos($trim, 'claude') !== false && strpos($trim, '=') !== false) {
            $line = 'CLAUDE_API_KEY=' . $apiKey;
            $found = true;
            break;
        }
    }
    unset($line);

    if (!$found) {
        $lines[] = 'CLAUDE_API_KEY=' . $apiKey;
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '' && substr($content, -1) !== PHP_EOL) {
        $content .= PHP_EOL;
    }

    if (file_put_contents($envPath, $content) === false) {
        sendResponse(false, null, '.env 파일을 쓸 수 없습니다. 서버 권한을 확인해 주세요.');
    }

    sendResponse(true, null, 'Claude API 키가 저장되었습니다.');
}

// Claude API 키 연결 테스트
if ($method === 'POST' && $action === 'testClaudeKey') {
    $testKey = isset($input['apiKey']) ? trim((string)$input['apiKey']) : '';
    $claudeKey = $testKey !== '' ? $testKey : getClaudeApiKey();
    $model = getClaudeModel();

    if ($claudeKey === '') {
        sendResponse(false, null, 'Claude API 키가 설정되지 않았습니다. 키를 입력 후 저장하거나, 입력 값으로 테스트해 주세요.');
    }

    try {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        $payload = [
            'model' => $model,
            'max_tokens' => 8,
            'messages' => [
                ['role' => 'user', 'content' => '연결 테스트입니다. 숫자 2만 출력해 주세요.'],
            ],
        ];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $claudeKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            sendResponse(false, null, '연결 실패: ' . $err);
        }
        curl_close($ch);

        $res = json_decode($raw, true);
        if ($http >= 200 && $http < 300 && isset($res['content'][0]['text'])) {
            sendResponse(true, ['http_code' => $http, 'model' => $model], 'Claude API 키가 정상적으로 연결되었습니다.');
        }

        $errMsg = $res['error']['message'] ?? ('HTTP ' . $http . ' 응답: ' . $raw);
        sendResponse(false, ['http_code' => $http, 'model' => $model], 'Claude API 테스트 실패: ' . $errMsg);
    } catch (Exception $e) {
        sendResponse(false, null, '연결 테스트 중 오류: ' . $e->getMessage());
    }
}

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

// 5. 남은 할일 기반 생산성 인사이트 생성 (Claude API 사용)
if ($method === 'POST' && $action === 'generateInsights') {
    $rangeStart = $_GET['start'] ?? ($input['start'] ?? null);
    $rangeEnd   = $_GET['end']   ?? ($input['end']   ?? null);

    $dateWhere = '';
    $params = [];
    if (!empty($rangeStart) && !empty($rangeEnd)) {
        $dateWhere = 'WHERE todo_date BETWEEN ? AND ?';
        $params = [$rangeStart, $rangeEnd];
    }

    try {
        // 5-1. 미완료 할일
        $sqlUndone = "SELECT id, todo_text, todo_date, completed, created_at
                      FROM todos
                      " . ($dateWhere ? $dateWhere . " AND completed = 0" : "WHERE completed = 0") . "
                      ORDER BY todo_date ASC, created_at ASC";
        $sqlUndone = str_replace('WHERE  AND', 'WHERE', $sqlUndone);
        $stmt = $pdo->prepare($sqlUndone);
        $stmt->execute($params);
        $undoneTodos = $stmt->fetchAll();

        // 5-2. 날짜별 완료/미완료 집계
        $sqlStats = "SELECT
                        todo_date,
                        SUM(completed = 1) AS done_count,
                        SUM(completed = 0) AS undone_count
                     FROM todos
                     " . ($dateWhere ?: '') . "
                     GROUP BY todo_date
                     ORDER BY todo_date ASC";
        $sqlStats = str_replace('WHERE  AND', 'WHERE', $sqlStats);
        $stmt = $pdo->prepare($sqlStats);
        $stmt->execute($params);
        $dailyStats = $stmt->fetchAll();

        $totalDone = 0;
        $totalUndone = 0;
        foreach ($dailyStats as $d) {
            $totalDone   += (int)$d['done_count'];
            $totalUndone += (int)$d['undone_count'];
        }

        $inputPayload = [
            'range_start' => $rangeStart,
            'range_end'   => $rangeEnd,
            'totals'      => [
                'done'   => $totalDone,
                'undone' => $totalUndone,
            ],
            'daily_stats' => $dailyStats,
            'undone_todos'=> $undoneTodos,
        ];
        $inputJson = json_encode($inputPayload, JSON_UNESCAPED_UNICODE);

        // 외부 프롬프트 파일을 읽어 지시어로 사용
        $promptFile = __DIR__ . '/insights_prompt.txt';
        $promptBase = '';
        if (file_exists($promptFile)) {
            $promptBase = (string)file_get_contents($promptFile);
        }
        if (trim($promptBase) === '') {
            // 프롬프트 파일이 없거나 비어 있을 때의 최소 지시어 (폴백)
            $promptBase = "# Role\n"
                . "당신은 할 일 목록 데이터를 분석하여 user_productivity_insights 테이블에 저장할 요약·패턴·병목·대책·동기 문구를 생성하는 분석가입니다.\n";
        }

        $prompt = $promptBase
            . "\n\n# Input Data (JSON)\n"
            . "아래 JSON은 통계에 남은 할일 목록과 일별 완료/미완료 통계입니다. 이 데이터를 기반으로 위 지시어에 따라 인사이트를 생성하십시오.\n\n"
            . $inputJson . "\n";

        $claudeKey = getClaudeApiKey();
        $model = getClaudeModel();
        if ($claudeKey === '') {
            sendResponse(false, null, 'Claude API 키가 설정되지 않았습니다. 리포트 팝업 하단의 키 입력란에서 먼저 저장해 주세요.');
        }

        $payload = [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        $maxRetries = 3;
        $raw = null;
        $http = 0;
        $lastErrMsg = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $claudeKey,
                    'anthropic-version:  ' . '2023-06-01',
                ],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 40,
            ]);
            $raw = curl_exec($ch);
            if ($raw === false) {
                $lastErrMsg = 'Claude API 호출 실패: ' . curl_error($ch);
                curl_close($ch);
                if ($attempt === $maxRetries) {
                    sendResponse(false, null, $lastErrMsg);
                }
                sleep(2 * $attempt);
                continue;
            }
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $apiRes = json_decode($raw, true);
            $errMsg = $apiRes['error']['message'] ?? '';

            $isOverloaded = ($http === 529 || $http === 503 || stripos((string)$errMsg, 'overloaded') !== false);
            if ($isOverloaded && $attempt < $maxRetries) {
                $lastErrMsg = 'Claude 서버가 일시적으로 바쁩니다. 재시도 중... (' . $attempt . '/' . $maxRetries . ')';
                sleep(2 * $attempt);
                continue;
            }

            $text = '';
            if (isset($apiRes['content'][0]['text'])) {
                $text = $apiRes['content'][0]['text'];
            } elseif (isset($apiRes['content'][0]['content'][0]['text'])) {
                $text = $apiRes['content'][0]['content'][0]['text'];
            }
            if ($http >= 200 && $http < 300 && $text !== '') {
                break;
            }
            $lastErrMsg = $errMsg ?: ('HTTP ' . $http);
            if ($attempt === $maxRetries) {
                sendResponse(false, ['http_code' => $http, 'model' => $model], 'Claude 인사이트 생성 실패: ' . $lastErrMsg);
            }
            sleep(2 * $attempt);
        }

        $apiRes = json_decode($raw, true);
        $text = '';
        if (isset($apiRes['content'][0]['text'])) {
            $text = $apiRes['content'][0]['text'];
        } elseif (isset($apiRes['content'][0]['content'][0]['text'])) {
            $text = $apiRes['content'][0]['content'][0]['text'];
        }
        if ($text === '') {
            sendResponse(false, ['http_code' => $http], 'Claude 인사이트 생성 실패: 응답 내용이 비어 있습니다.');
        }

        $lines = preg_split('/\\r?\\n/', (string)$text);
        $map = [
            'COL_SUMMARY'             => 'summary_text',
            'COL_SEMANTIC_PATTERN'    => 'semantic_pattern',
            'COL_COGNITIVE_LOAD_TYPE' => 'cognitive_load_type',
            'COL_BOTTLENECK_ANALYSIS' => 'bottleneck_analysis',
            'COL_ACTION_PLAN_1'       => 'action_plan_1',
            'COL_ACTION_PLAN_2'       => 'action_plan_2',
            'COL_MOTIVATION_QUOTE'    => 'motivation_quote',
        ];
        $values = [
            'summary_text'        => '',
            'semantic_pattern'    => '',
            'cognitive_load_type' => '',
            'bottleneck_analysis' => '',
            'action_plan_1'       => '',
            'action_plan_2'       => '',
            'motivation_quote'    => '',
        ];

        foreach ($lines as $line) {
            $line = trim($line, "- \t");
            foreach ($map as $colKey => $field) {
                $prefix = $colKey . ':';
                if (stripos($line, $prefix) === 0) {
                    $val = trim(substr($line, strlen($prefix)));
                    $values[$field] = $val;
                }
            }
        }

        // 기존 DB에서 cognitive_load_type이 VARCHAR(100)이면 TEXT로 확장 (심리 분석 길이 대응)
        try {
            $pdo->exec("ALTER TABLE user_productivity_insights MODIFY cognitive_load_type TEXT NOT NULL");
        } catch (Exception $alterEx) {
            // 이미 TEXT이거나 권한 문제 등은 무시하고 INSERT 시도
        }

        $stmt = $pdo->prepare("
            INSERT INTO user_productivity_insights
            (analysis_date_start, analysis_date_end,
             summary_text, semantic_pattern, cognitive_load_type,
             bottleneck_analysis, action_plan_1, action_plan_2, motivation_quote)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $rangeStart,
            $rangeEnd,
            $values['summary_text'],
            $values['semantic_pattern'],
            $values['cognitive_load_type'],
            $values['bottleneck_analysis'],
            $values['action_plan_1'],
            $values['action_plan_2'],
            $values['motivation_quote'],
        ]);

        sendResponse(true, [
            'stored' => $values,
            'range'  => ['start' => $rangeStart, 'end' => $rangeEnd],
        ], '인사이트가 생성되어 DB에 저장되었습니다.');
    } catch (Exception $e) {
        sendResponse(false, null, '인사이트 생성 오류: ' . $e->getMessage());
    }
}

sendResponse(false, null, '잘못된 요청입니다.');
