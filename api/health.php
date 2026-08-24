<?php
require_once __DIR__ . '/lib/supabase.php';

header('Content-Type: application/json');

$hasUrl = getenv('SUPABASE_URL') !== false && getenv('SUPABASE_URL') !== '';
$hasKey = getenv('SUPABASE_SERVICE_ROLE_KEY') !== false && getenv('SUPABASE_SERVICE_ROLE_KEY') !== '';

$result = [
    'php_version' => PHP_VERSION,
    'env_vars_present' => [
        'SUPABASE_URL' => $hasUrl,
        'SUPABASE_SERVICE_ROLE_KEY' => $hasKey,
    ],
    'supabase_reachable' => false,
];

if ($hasUrl && $hasKey) {
    try {
        // A cheap query just to confirm the table exists and creds work.
        supabase_request('GET', '/access_codes?select=id&limit=1');
        $result['supabase_reachable'] = true;
    } catch (Throwable $e) {
        $result['supabase_error'] = $e->getMessage();
    }
}

echo json_encode($result, JSON_PRETTY_PRINT);
