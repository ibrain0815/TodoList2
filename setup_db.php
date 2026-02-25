<?php
$config = require __DIR__ . '/config.php';
$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

try {
    $pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // DB 생성
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");

    // todos 테이블 생성 (순서 유지를 위해 sort_order 포함)
    $pdo->exec("CREATE TABLE IF NOT EXISTS todos (
        id BIGINT PRIMARY KEY,
        todo_text TEXT NOT NULL,
        completed TINYINT(1) DEFAULT 0,
        todo_date DATE NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_todo_date (todo_date),
        INDEX idx_sort (todo_date, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // recent_todos 테이블 생성
    $pdo->exec("CREATE TABLE IF NOT EXISTS recent_todos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        todo_text VARCHAR(255) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 생산성 인사이트 저장 테이블 생성
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_productivity_insights (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        analysis_date_start DATE NULL,
        analysis_date_end   DATE NULL,
        summary_text        TEXT NOT NULL,
        semantic_pattern    TEXT NOT NULL,
        cognitive_load_type TEXT NOT NULL,
        bottleneck_analysis TEXT NOT NULL,
        action_plan_1       TEXT NOT NULL,
        action_plan_2       TEXT NOT NULL,
        motivation_quote    TEXT NOT NULL,
        created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_upi_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "데이터베이스 및 테이블 생성 완료!\n";

} catch (PDOException $e) {
    die("오류 발생: " . $e->getMessage() . "\n");
}
