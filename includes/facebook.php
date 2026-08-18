<?php
/**
 * Meta (Facebook) Conversions API — server-side Lead event.
 *
 * Sends a "Lead" event to the pixel so conversions are captured even when the
 * browser Pixel is blocked. Pass the SAME event_id the Pixel used so Meta
 * deduplicates the two. Credentials are baked in with an env override; set
 * FB_PIXEL_ID / FB_CAPI_TOKEN in .env to override.
 *
 * Fire-and-forget: never blocks or fails the signup.
 */

require_once __DIR__ . '/../config/database.php'; // provides env()

function fb_hash($value) {
    $value = strtolower(trim((string) $value));
    return $value === '' ? '' : hash('sha256', $value);
}

function fb_hash_phone($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') return '';
    if (strlen($digits) === 10) { $digits = '1' . $digits; } // assume US when no country code
    return hash('sha256', $digits);
}

function sendFacebookLead($p) {
    $pixelId = env('FB_PIXEL_ID', '1131224344235309');
    $token   = env('FB_CAPI_TOKEN', 'EAArdgnWHc9oBQofh4jo1BpJAjavb8KkEWAJO9aF5AWKsZB2ccLbS0O6g8Q3QyX9Po2asmzP7iH2HdqTljPMqCZCbvopUEy2YXkIJ7ijozoDbk8nxTFLUZCxYILqGjn2BTc5rdtvMQ2rYCLwYP1ZCCQI27CfCv6rrQZCA2sSAX6w2Rsxy6YdUqVdmeBskC4gZDZD');
    if (!$pixelId || !$token) return false;

    $userData = array_filter([
        'em'                => !empty($p['email']) ? [fb_hash($p['email'])] : null,
        'ph'                => !empty($p['phone']) ? [fb_hash_phone($p['phone'])] : null,
        'client_ip_address' => !empty($p['ip']) ? $p['ip'] : null,
        'client_user_agent' => !empty($p['user_agent']) ? $p['user_agent'] : null,
        'fbp'               => !empty($p['fbp']) ? $p['fbp'] : null,
        'fbc'               => !empty($p['fbc']) ? $p['fbc'] : null,
    ]);

    $event = array_filter([
        'event_name'       => 'Lead',
        'event_time'       => time(),
        'action_source'    => 'website',
        'event_id'         => !empty($p['event_id']) ? $p['event_id'] : null,
        'event_source_url' => !empty($p['event_source_url']) ? $p['event_source_url'] : null,
        'user_data'        => $userData,
    ], function ($v) { return $v !== null; });

    $payload = json_encode(['data' => [$event]]);
    $url = "https://graph.facebook.com/v19.0/{$pixelId}/events?access_token=" . urlencode($token);

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 300) { error_log("FB CAPI Lead HTTP $code: " . $resp); }
        return true;
    } catch (Exception $e) {
        error_log("FB CAPI Lead failed: " . $e->getMessage());
        return false;
    }
}
