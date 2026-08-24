<?php
// Thin wrapper around Supabase's auto-generated REST API (PostgREST).
// There's no official Supabase SDK for PHP, but the REST API is just
// plain HTTP + JSON, so cURL is all we need.

/**
 * Low level request helper. Talks to https://<project>.supabase.co/rest/v1/...
 * using the service_role key, which bypasses Row Level Security. This key
 * must NEVER be exposed to the browser - it only ever lives in this
 * server-side file, read from a Vercel environment variable.
 */
function supabase_request(string $method, string $path, ?array $body = null): array
{
    $baseUrl = rtrim((string) getenv('SUPABASE_URL'), '/');
    $serviceKey = (string) getenv('SUPABASE_SERVICE_ROLE_KEY');

    if ($baseUrl === '' || $serviceKey === '') {
        throw new RuntimeException(
            'Missing SUPABASE_URL or SUPABASE_SERVICE_ROLE_KEY environment variables.'
        );
    }

    $url = $baseUrl . '/rest/v1' . $path;

    $headers = [
        'apikey: ' . $serviceKey,
        'Authorization: Bearer ' . $serviceKey,
        'Content-Type: application/json',
    ];

    // Ask PostgREST to hand back the affected row(s) so we can inspect the
    // result of an update without a second round trip.
    if ($method === 'PATCH' || $method === 'POST') {
        $headers[] = 'Prefer: return=representation';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // Note: curl_close() is intentionally not called here. As of PHP 8.0
    // curl handles are freed automatically when they go out of scope, and
    // as of PHP 8.5 calling curl_close() explicitly raises a deprecation
    // notice (since it's a no-op). Calling it caused that exact notice to
    // be printed into the response body ahead of our JSON, which broke
    // fetch().json() parsing on the frontend.

    if ($response === false) {
        throw new RuntimeException('Could not reach Supabase: ' . $curlError);
    }

    $decoded = json_decode($response, true);

    return [
        'status' => $httpCode,
        'data' => $decoded,
        'url' => $url,
    ];
}

/**
 * Look up a code regardless of its used state. Returns null if it doesn't
 * exist at all.
 */
function supabase_find_code(string $code): ?array
{
    $encoded = rawurlencode($code);
    $result = supabase_request(
        'GET',
        "/access_codes?code=eq.$encoded&select=id,code,used,used_at,event_name&limit=1"
    );

    if ($result['status'] >= 400) {
        throw new RuntimeException(
            'Supabase lookup failed (HTTP ' . $result['status'] . ') for URL '
            . $result['url'] . ': ' . json_encode($result['data'])
        );
    }

    if (empty($result['data'])) {
        return null;
    }

    return $result['data'][0];
}

/**
 * Atomically claim a code: flips used=false -> used=true in a single
 * request, filtered on used=eq.false. This avoids a race condition where
 * two near-simultaneous scans of the same QR code could both pass a
 * separate "is it used?" check before either one writes the update.
 *
 * Returns the updated row on success, or null if no row matched the
 * filter (meaning it was already used by the time this ran, or the code
 * doesn't exist - the caller distinguishes those with supabase_find_code).
 */
function supabase_claim_code(string $code): ?array
{
    $encoded = rawurlencode($code);
    $result = supabase_request(
        'PATCH',
        "/access_codes?code=eq.$encoded&used=eq.false",
        [
            'used' => true,
            'used_at' => gmdate('c'),
        ]
    );

    // PostgREST's documented (if surprising) behavior: a PATCH with
    // "Prefer: return=representation" that matches zero rows returns
    // HTTP 404 with an empty array, not 200. That's the normal, expected
    // outcome here whenever the code is already used or doesn't exist -
    // NOT a real error - so we treat it as "no match" rather than throw.
    if ($result['status'] === 404) {
        return null;
    }

    if ($result['status'] >= 400) {
        throw new RuntimeException(
            'Supabase update failed (HTTP ' . $result['status'] . ') for URL '
            . $result['url'] . ': ' . json_encode($result['data'])
        );
    }

    if (empty($result['data'])) {
        return null;
    }

    return $result['data'][0];
}
