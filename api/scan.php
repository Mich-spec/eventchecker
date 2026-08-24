<?php
// Never let notices/warnings/deprecations print into the response body -
// that would corrupt the JSON and break fetch().json() on the frontend.
// ob_start() buffers everything (including any stray warning text); the
// respond() helper below discards that buffer right before sending clean
// JSON, so leaked warnings never reach the client but nothing swallows
// the real response either.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/lib/supabase.php';

/**
 * Send a clean JSON response, discarding any buffered output (stray
 * warnings/notices) that may have accumulated before this point.
 */
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['status' => 'error', 'message' => 'Method not allowed.']);
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

$code = trim((string) ($input['code'] ?? ''));

if ($code === '') {
    respond(400, ['status' => 'error', 'message' => 'No QR code was provided.']);
}

try {
    // Try to atomically claim the code (flip used=false -> true) in one
    // request. If that succeeds, we know for certain we were the ones
    // who just checked this code in - no race condition with another
    // scanner hitting the same code at the same moment.
    $claimed = supabase_claim_code($code);

    if ($claimed !== null) {
        respond(200, [
            'status' => 'success',
            'message' => 'Access granted. Welcome!',
            'code' => $code,
            'event_name' => $claimed['event_name'] ?? null,
            'used_at' => $claimed['used_at'] ?? null,
        ]);
    }

    // The claim matched zero rows. That means either the code doesn't
    // exist at all, or it exists but was already used. Look it up to
    // tell those two cases apart.
    $existing = supabase_find_code($code);

    if ($existing === null) {
        respond(404, [
            'status' => 'invalid',
            'message' => 'This QR code does not exist in the system.',
            'code' => $code,
        ]);
    }

    respond(409, [
        'status' => 'already_used',
        'message' => 'This access card has already been used.',
        'code' => $code,
        'used_at' => $existing['used_at'],
    ]);
} catch (Throwable $e) {
    respond(500, [
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
    ]);
}
