<?php
/**
 * Push a signup to the owner's GoHighLevel sub-account.
 *
 *   1) Upsert the contact (name/email/phone + custom fields + timezone). Tags are
 *      intentionally NOT sent here, so any existing tags on the contact are kept.
 *   2) Append the signup tags via a separate /tags call (adds, never overwrites).
 *
 * Fire-and-forget: never blocks or fails the signup. Credentials baked in with
 * env overrides (GHL_SIGNUP_TOKEN / GHL_SIGNUP_LOCATION).
 */

require_once __DIR__ . '/../config/database.php'; // provides env()

function sendSignupToGHL($d) {
    $token      = env('GHL_SIGNUP_TOKEN', 'pit-c783c99a-a551-427c-ba0b-f9c18cfd820a');
    $locationId = env('GHL_SIGNUP_LOCATION', 'rZ5eDWGmionEGPWr3cj4');
    if (!$token || !$locationId) return false;

    // Custom field IDs created in GHL (naming: "Lead Gen Software Signup - X").
    $fieldMap = [
        '6ARletynbr4AppqeOwq6' => $d['entry_date'] ?? '',       // Entry Date
        'ap9yqRHOossANALJJ4DH' => $d['wants_ownership'] ?? '',   // Interested In Buying Software
        'sIjcK1a2Qgdx6YHZWNzy' => $d['source'] ?? '',           // Source
        'cqDZCgL9YpWX2nGdWc3U' => $d['utm_source'] ?? '',       // UTM Source
        'r2eqyMSo0qHOIIfF0E27' => $d['utm_medium'] ?? '',       // UTM Medium
        'u2vLKn1CCCL5g3MrJKlU' => $d['utm_campaign'] ?? '',     // UTM Campaign
        'E4VA3uqqDQ0yKRO3rFvp' => $d['fbcampaignid'] ?? '',     // FB Campaign ID
        'UswsBNzabjcgvfEhLXoK' => $d['fbplacement'] ?? '',      // FB Placement
        'SC13ZAAN3J0W5rfCqIMH' => $d['fbadsetid'] ?? '',        // FB Adset ID
        'DR4NDBixzTlJWJWLzDlr' => $d['fbadid'] ?? '',           // FB Ad ID
        'QpGUbTKr42H5l0IF0QMc' => $d['referrer'] ?? '',         // Referrer
        'Px9m4pWpXuVLZkzvX22t' => $d['ip'] ?? '',               // IP
        '7Wtt2YRi9yA0GxdOVjoP' => $d['user_agent'] ?? '',       // User Agent
    ];
    $customFields = [];
    foreach ($fieldMap as $id => $val) {
        if ($val !== '' && $val !== null) { $customFields[] = ['id' => $id, 'value' => (string) $val]; }
    }

    $name = trim($d['name'] ?? '');
    $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
    $firstName = $parts[0] ?? '';
    $lastName  = $parts[1] ?? '';

    $body = array_filter([
        'locationId'   => $locationId,
        'firstName'    => $firstName,
        'lastName'     => $lastName,
        'name'         => $name,
        'email'        => $d['email'] ?? '',
        'phone'        => $d['phone'] ?? '',
        'timezone'     => $d['timezone'] ?? '',   // IANA zone, e.g. America/New_York
        'customFields' => $customFields,
    ], function ($v) { return $v !== '' && $v !== null && $v !== []; });

    $headers = [
        'Authorization: Bearer ' . $token,
        'Version: 2021-07-28',
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // 1) Upsert the contact (no tags in the body -> existing tags untouched).
    $contactId = null;
    try {
        $ch = curl_init('https://services.leadconnectorhq.com/contacts/upsert');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($resp, true);
        $contactId = $json['contact']['id'] ?? ($json['id'] ?? null);
        if (!$contactId) { error_log("GHL upsert: no contact id (HTTP $code): " . $resp); return false; }
    } catch (Exception $e) {
        error_log("GHL upsert failed: " . $e->getMessage());
        return false;
    }

    // 2) Append the signup tags (adds only; preserves existing tags).
    try {
        $ch = curl_init("https://services.leadconnectorhq.com/contacts/{$contactId}/tags");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['tags' => ['lead gen software signup', 'lead gen software signup dnd']]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 8,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        error_log("GHL add tags failed: " . $e->getMessage());
    }

    return true;
}
