<?php
// Same output-safety pattern as scan.php/health.php: buffer everything so
// a stray PHP notice/warning can never corrupt the JSON response body.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/lib/supabase.php';

function respond(int $httpStatus, array $payload): void
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpStatus);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

try {
    // Only codes that have actually been checked in, most recent first.
    $result = supabase_request(
        'GET',
        '/access_codes?used=eq.true&select=code,used,used_at&order=used_at.desc&limit=1000'
    );

    if ($result['status'] >= 400) {
        throw new RuntimeException(
            'Supabase lookup failed (HTTP ' . $result['status'] . '): '
            . json_encode($result['data'])
        );
    }

    $cards = $result['data'] ?? [];

    respond(200, [
        'status' => 'success',
        'count' => count($cards),
        'cards' => $cards,
    ]);
} catch (Throwable $e) {
    respond(500, [
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
