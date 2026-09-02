<?php

header('Content-Type: application/json');

if (($_GET['ok'] ?? '') === 'false') {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);

    return;
}

http_response_code((int) ($_GET['code'] ?? 200));
echo json_encode([
    'ok'     => true,
    'method' => $_SERVER['REQUEST_METHOD'],
    'echo'   => json_decode(file_get_contents('php://input'), true) ?? null,
]);
