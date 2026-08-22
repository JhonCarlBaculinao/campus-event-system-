<?php

/*
|--------------------------------------------------------------------------
| TOTP (Time-based One-Time Password) Helper — RFC 6238
|--------------------------------------------------------------------------
|
| Pure PHP implementation used for Two-Factor Authentication.
| Compatible with Google Authenticator, Authy, Microsoft Authenticator,
| and other standard TOTP apps (SHA1 / 6 digits / 30-second period).
|
*/

/**
 * Decode an uppercase base32 string (RFC 4648, no padding).
 */
function base32_decode_rmc(string $base32): string
{
    $base32 = strtoupper(rtrim($base32, '='));

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $map = [];

    for ($i = 0; $i < 32; $i++) {
        $map[$alphabet[$i]] = $i;
    }

    $buffer = 0;
    $bits   = 0;
    $out    = '';

    for ($i = 0; $i < strlen($base32); $i++) {

        $char = $base32[$i];

        if (!isset($map[$char])) {
            continue;
        }

        $buffer = ($buffer << 5) | $map[$char];
        $bits  += 5;

        if ($bits >= 8) {
            $out  .= chr(($buffer >> ($bits - 8)) & 0xFF);
            $bits -= 8;
        }
    }

    return $out;
}

/**
 * Generate the 6-digit TOTP code for a given 30-second counter.
 */
function totp_code_at(string $secret, int $counter): string
{
    $key  = base32_decode_rmc($secret);
    $time = pack('N*', 0) . pack('N', $counter);

    $hash   = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;

    $value =
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
        (ord($hash[$offset + 3])  & 0xFF);

    return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Verify a submitted 6-digit code against the secret.
 *
 * @param int $window allowed drift in 30-second steps (default +/-1).
 */
function totp_verify(string $secret, string $code, int $window = 1): bool
{
    if (!preg_match('/^[0-9]{6}$/', $code)) {
        return false;
    }

    $counter = (int) floor(time() / 30);

    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($secret, $counter + $i), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Generate a random 32-character base32 secret (160-bit entropy).
 */
function totp_generate_secret(int $bytes = 20): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret   = '';
    $random   = random_bytes($bytes);

    for ($i = 0; $i < $bytes; $i++) {
        $secret .= $alphabet[ord($random[$i]) & 0x1F];
    }

    return $secret;
}

/**
 * Build the otpauth:// provisioning URI for authenticator app QR codes.
 */
function totp_otpauth_uri(
    string $label,
    string $secret,
    string $issuer = 'RMC Events'
): string {
    $label = preg_replace('/[^A-Za-z0-9 ]/', '', $label);

    return 'otpauth://totp/'
        . rawurlencode($issuer)
        . ':'
        . rawurlencode($label)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}
