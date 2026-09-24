<?php

/*
|--------------------------------------------------------------------------
| QR ATTENDANCE SIGNED TOKENS
|--------------------------------------------------------------------------
|
| Short-lived, cryptographically signed attendance tokens.
|
| Token format:  RMC1.<payload_b64url>.<signature_b64url>
|
|   payload   = base64url( json( {v, rid, eid, exp, n} ) )
|   signature = base64url( HMAC-SHA256( QR_HMAC_KEY, payload ) )
|
|   v   = token version (1)
|   rid = registration_id (binds the token to one registration)
|   eid = event_id        (binds the token to one event)
|   exp = unix expiry     (tokens are short-lived)
|   n   = random nonce    (freshness)
|
| Validation is done server-side only (scan_attendance.php). The token
| itself carries no private data and cannot be forged without the key.
|
| This file doubles as:
|   1. an include-able helper library (qr_token_mint / qr_token_verify),
|   2. a JSON mint endpoint for the student QR page to rotate codes.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'db_connect.php';
require_once 'lang.php';
require_once 'csrf.php';

if (!defined('QR_TOKEN_TTL')) {
    define('QR_TOKEN_TTL', 60);
}

/*
|--------------------------------------------------------------------------
| base64url helpers
|--------------------------------------------------------------------------
*/

function qr_b64url_encode(string $raw): string
{
    return rtrim(
        strtr(base64_encode($raw), '+/', '-_'),
        '='
    );
}

function qr_b64url_decode(string $b64): ?string
{
    $b64 = strtr($b64, '-_', '+/');
    $pad = strlen($b64) % 4;

    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    $raw = base64_decode($b64, true);

    return $raw === false ? null : $raw;
}

/*
|--------------------------------------------------------------------------
| Sign / verify
|--------------------------------------------------------------------------
*/

function qr_token_sign(string $payload): string
{
    return qr_b64url_encode(
        hash_hmac('sha256', $payload, QR_HMAC_KEY, true)
    );
}

function qr_token_mint(int $registration_id, int $event_id): string
{
    $payload = json_encode(array(
        'v'   => 1,
        'rid' => $registration_id,
        'eid' => $event_id,
        'exp' => time() + QR_TOKEN_TTL,
        'n'   => bin2hex(random_bytes(6)),
    ));

    $payload_b64 = qr_b64url_encode($payload);

    return 'RMC1.' . $payload_b64 . '.' . qr_token_sign($payload_b64);
}

/*
|--------------------------------------------------------------------------
| Validate a token.
|
| Returns:
|   ['ok' => true,  'data' => ['rid'=>..,'eid'=>..,'exp'=>..,'n'=>..]]
|   ['ok' => false, 'expired' => bool, 'reason' => string]
|--------------------------------------------------------------------------
*/

function qr_token_verify(string $token): array
{
    $parts = explode('.', $token);

    if (count($parts) !== 3 || $parts[0] !== 'RMC1') {
        return array(
            'ok'      => false,
            'expired' => false,
            'reason'  => 'format',
        );
    }

    $payload_b64 = $parts[1];
    $sig         = $parts[2];

    // Reject empty / oversized payloads early.
    if ($payload_b64 === '' || strlen($payload_b64) > 512) {
        return array(
            'ok'      => false,
            'expired' => false,
            'reason'  => 'format',
        );
    }

    $expected = qr_token_sign($payload_b64);

    if (!hash_equals($expected, $sig)) {
        return array(
            'ok'      => false,
            'expired' => false,
            'reason'  => 'signature',
        );
    }

    $json = qr_b64url_decode($payload_b64);

    if ($json === null) {
        return array(
            'ok'      => false,
            'expired' => false,
            'reason'  => 'format',
        );
    }

    $data = json_decode($json, true);

    if (
        !is_array($data) ||
        ($data['v'] ?? null) !== 1 ||
        !isset($data['rid'], $data['eid'], $data['exp'], $data['n']) ||
        !is_int($data['rid']) ||
        !is_int($data['eid']) ||
        !is_int($data['exp'])
    ) {
        return array(
            'ok'      => false,
            'expired' => false,
            'reason'  => 'payload',
        );
    }

    if ($data['exp'] <= time()) {
        return array(
            'ok'      => true,
            'expired' => true,
            'data'    => $data,
        );
    }

    return array(
        'ok'      => true,
        'expired' => false,
        'data'    => $data,
    );
}

/*
|--------------------------------------------------------------------------
| MINT ENDPOINT  (qr_token.php?rids=1,2,3  — POST + CSRF)
|
| Returns JSON: { "tokens": { "1": "RMC1....", "2": "RMC1...." } }
| Only mints tokens for registrations owned by the logged-in student
| on approved events.
|--------------------------------------------------------------------------
*/

if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'qr_token.php') {

    header('Content-Type: application/json; charset=UTF-8');

    if (
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['role']) ||
        $_SESSION['role'] !== 'student'
    ) {
        http_response_code(403);
        echo json_encode(array('error' => 'Access denied. Students only.'));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(array('error' => 'POST required.'));
        exit;
    }

    csrf_verify();

    $rids_raw = trim((string) ($_POST['rids'] ?? ''));

    if ($rids_raw === '') {
        http_response_code(422);
        echo json_encode(array('error' => 'No registrations specified.'));
        exit;
    }

    $rids = array_values(
        array_filter(
            array_map(
                'intval',
                explode(',', $rids_raw)
            ),
            function ($id) {
                return $id > 0;
            }
        )
    );

    if (count($rids) === 0) {
        http_response_code(422);
        echo json_encode(array('error' => 'Invalid registration ids.'));
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | MySQL-compatible IN(...) query.
    |
    | Build one "?" placeholder per registration id, then bind the
    | user_id first, followed by each rid in order. This replaces the
    | old PostgreSQL-only "= ANY(?[])" array syntax, which MySQL's PDO
    | driver does not support at all (it was also malformed — the
    | prepare()/execute() calls had been collapsed into a single
    | invalid expression).
    |--------------------------------------------------------------------------
    */

    $placeholders = implode(',', array_fill(0, count($rids), '?'));

    $sql = "
        SELECT r.registration_id, r.event_id
        FROM registrations r
        JOIN events e
            ON e.event_id = r.event_id
        WHERE r.user_id = ?
          AND r.status = 'registered'
          AND e.status = 'approved'
          AND r.registration_id IN ($placeholders)
    ";

    $params = array_merge(
        array($_SESSION['user_id']),
        $rids
    );

    $result = $pdo->prepare($sql);
    $result->execute($params);

    $tokens = array();

    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        $tokens[(string) $row['registration_id']] =
            qr_token_mint(
                (int) $row['registration_id'],
                (int) $row['event_id']
            );
    }

    echo json_encode(array(
        'ttl'    => QR_TOKEN_TTL,
        'tokens' => $tokens,
    ));
    exit;
}