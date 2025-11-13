<?php
session_start();
header('Content-Type: application/json');

$logDir      = __DIR__ . '/logs';
$actionsFile = $logDir . '/actions_log.json';

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data || !isset($data['mode']) || $data['mode'] !== 'log_action') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$event = [
    "timestamp_server" => date('c'),
    "timestamp_client" => $data['timestamp'] ?? null,
    "action_type"      => $data['actionType'] ?? null,
    "question_id"      => $data['questionId'] ?? null,
    "value"            => $data['value'] ?? null,
    "student_name"     => $data['studentName'] ?? null,
    "student_id"       => $data['studentId'] ?? null,
    "session_id"       => session_id(),
    "ip"               => $_SERVER['REMOTE_ADDR'] ?? null,
    "user_agent"       => $_SERVER['HTTP_USER_AGENT'] ?? null
];

$existing = [];
if (file_exists($actionsFile)) {
    $json = file_get_contents($actionsFile);
    $existing = json_decode($json, true);
    if (!is_array($existing)) $existing = [];
}

$existing[] = $event;

file_put_contents($actionsFile, json_encode($existing, JSON_PRETTY_PRINT), LOCK_EX);

echo json_encode(['status' => 'ok']);
