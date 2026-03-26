<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
http_response_code(501);
echo json_encode([
    'status' => 'error',
    'message' => 'Rigs API stub — not used by current panel',
]);
