<?php
require_once __DIR__ . '/lib/supabase.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

$code = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No QR code was provided.']);
    exit;
}

try {
    // Try to atomically claim the code (flip used=false -> true) in one
    // request. If that succeeds, we know for certain we were the ones
    // who just checked this code in - no race condition with another
    // scanner hitting the same code at the same moment.
    $claimed = supabase_claim_code($code);

    if ($claimed !== null) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Access granted. Welcome!',
            'code' => $code,
            'event_name' => $claimed['event_name'] ?? null,
            'used_at' => $claimed['used_at'] ?? null,
        ]);
        exit;
    }

    // The claim matched zero rows. That means either the code doesn't
    // exist at all, or it exists but was already used. Look it up to
    // tell those two cases apart.
    $existing = supabase_find_code($code);

    if ($existing === null) {
        http_response_code(404);
        echo json_encode([
            'status' => 'invalid',
            'message' => 'This QR code does not exist in the system.',
            'code' => $code,
        ]);
        exit;
    }

    http_response_code(409);
    echo json_encode([
        'status' => 'already_used',
        'message' => 'This access card has already been used.',
        'code' => $code,
        'used_at' => $existing['used_at'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
