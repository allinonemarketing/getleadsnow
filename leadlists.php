<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/rapidapi.php';
require_once 'config/subscription_config.php';
require_once __DIR__ . '/includes/search_lib.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$userId = $_SESSION['user_id'];
$isAdminViewing = isset($_SESSION['admin_original_id']);
$stmt = $pdo->prepare("SELECT credits, shared_for_credits, subscription_plan FROM users WHERE id = ?");
$stmt->execute([$userId]);
$uRow = $stmt->fetch(PDO::FETCH_ASSOC);
$userCredits = $uRow['credits'] ?? 0;
$userHasShared = $uRow['shared_for_credits'] ?? 0;
$userPlan = $uRow['subscription_plan'] ?? 'none';

// --- Billing helpers ---------------------------------------------------------
// Model: 1 credit per lead returned by a search (charged as leads are saved in
// addLeads). Enrichment is FREE. reserveCredit() atomically decrements only if
// the balance covers it, so it doubles as the "can they afford this?" gate.
function reserveCredit($pdo, $userId, $n = 1) {
    $s = $pdo->prepare("UPDATE users SET credits = credits - ? WHERE id = ? AND credits >= ?");
    $s->execute([$n, $userId, $n]);
    return $s->rowCount() > 0;            // true only if the balance covered it
}
function refundCredit($pdo, $userId, $n = 1) {
    $pdo->prepare("UPDATE users SET credits = credits + ? WHERE id = ?")->execute([$n, $userId]);
}
function currentCredits($pdo, $userId) {
    $s = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}
// -----------------------------------------------------------------------------

// Throttled to at most once per 60s so high-frequency enrichment polling doesn't
// turn every read-only API call into a write (redo/binlog churn on the same row).
try { $pdo->prepare("UPDATE users SET last_active_at = NOW() WHERE id = ? AND (last_active_at IS NULL OR last_active_at < NOW() - INTERVAL 60 SECOND)")->execute([$userId]); } catch (Exception $e) {}

// Auto-create tables / run migrations.
// Gated behind a schema-version flag so these ~18 preflight queries run only once
// per deploy instead of on every request (they were adding remote-DB latency to
// every list load). Bump $schemaVersion whenever a migration is added below.
$schemaVersion = 'v3-2026-08';
$schemaFlagFile = sys_get_temp_dir() . '/getleadsnow_leadlists_schema_' . md5($schemaVersion) . '.ok';
if (!@is_file($schemaFlagFile)) {
try {
    $pdo->query("SELECT 1 FROM lead_lists LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE lead_lists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    )");
}

try {
    $pdo->query("SELECT 1 FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE lead_list_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        list_id INT NOT NULL,
        user_id INT NOT NULL,
        business_id VARCHAR(255),
        business_name VARCHAR(500),
        address VARCHAR(500),
        city VARCHAR(255),
        state VARCHAR(255),
        phone VARCHAR(100),
        website VARCHAR(500),
        rating DECIMAL(3,2),
        review_count INT DEFAULT 0,
        types TEXT,
        latitude DECIMAL(10,8),
        longitude DECIMAL(11,8),
        emails JSON,
        social_media_links JSON,
        notes TEXT,
        status ENUM('cold','warm','hot') DEFAULT 'cold',
        visited_website TINYINT DEFAULT 0,
        visited_social TINYINT DEFAULT 0,
        reached_out TINYINT DEFAULT 0,
        website_visited_at DATETIME NULL,
        social_visited_at DATETIME NULL,
        reached_out_at DATETIME NULL,
        raw_data JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_list (list_id),
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_list_status (list_id, status),
        FULLTEXT idx_search (business_name, address, notes)
    )");
}

try {
    $pdo->query("SELECT 1 FROM lead_list_searches LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE lead_list_searches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        list_id INT NOT NULL,
        user_id INT NOT NULL,
        search_query VARCHAR(500),
        state_name VARCHAR(255),
        city VARCHAR(255),
        results_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_list (list_id)
    )");
}

try {
    $pdo->query("SELECT enriched_at FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN enriched_at DATETIME NULL DEFAULT NULL");
}

try {
    $pdo->query("SELECT visited_socials FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN visited_socials JSON DEFAULT NULL");
}

try {
    $pdo->query("SELECT pipeline_stage FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN pipeline_stage VARCHAR(20) DEFAULT 'new'");
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN outreach_email TINYINT DEFAULT 0");
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN outreach_instagram TINYINT DEFAULT 0");
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN follow_up_count INT DEFAULT 0");
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN first_contacted_at DATETIME NULL DEFAULT NULL");
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN last_follow_up_at DATETIME NULL DEFAULT NULL");
    $pdo->exec("UPDATE lead_list_items SET pipeline_stage = 'contacted' WHERE reached_out = 1");
}

try {
    $pdo->query("SELECT enrichment_status FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN enrichment_status VARCHAR(20) DEFAULT NULL");
    $pdo->exec("UPDATE lead_list_items SET enrichment_status = 'completed' WHERE enriched_at IS NOT NULL");
}

try {
    $pdo->query("SELECT replicate_id FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN replicate_id VARCHAR(64) DEFAULT NULL");
}

try {
    $pdo->query("SELECT public_token FROM lead_lists LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_lists ADD COLUMN is_public TINYINT DEFAULT 0");
    $pdo->exec("ALTER TABLE lead_lists ADD COLUMN public_token VARCHAR(64) DEFAULT NULL");
    $pdo->exec("ALTER TABLE lead_lists ADD UNIQUE INDEX idx_public_token (public_token)");
}

try {
    $pdo->query("SELECT ghl_api_key FROM users LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE users ADD COLUMN ghl_api_key VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN ghl_location_id VARCHAR(100) DEFAULT NULL");
}

try {
    $pdo->query("SELECT ghl_contact_id FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN ghl_contact_id VARCHAR(100) DEFAULT NULL");
}

try {
    $pdo->query("SELECT has_email FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN has_email TINYINT DEFAULT 0, ADD COLUMN has_socials TINYINT DEFAULT 0");
    $pdo->exec("UPDATE lead_list_items SET has_email = 1 WHERE JSON_LENGTH(COALESCE(emails, '[]')) > 0");
    $pdo->exec("UPDATE lead_list_items SET has_socials = 1 WHERE JSON_LENGTH(COALESCE(social_media_links, '[]')) > 0");
}

try {
    $pdo->query("SELECT has_phone FROM lead_list_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("ALTER TABLE lead_list_items ADD COLUMN has_phone TINYINT DEFAULT 0, ADD COLUMN has_website TINYINT DEFAULT 0, ADD COLUMN has_notes TINYINT DEFAULT 0");
    $pdo->exec("UPDATE lead_list_items SET has_phone = 1 WHERE phone IS NOT NULL AND phone != ''");
    $pdo->exec("UPDATE lead_list_items SET has_website = 1 WHERE website IS NOT NULL AND website != ''");
    $pdo->exec("UPDATE lead_list_items SET has_notes = 1 WHERE notes IS NOT NULL AND notes != ''");
}

try {
    $result = $pdo->query("SHOW INDEX FROM lead_list_items WHERE Key_name = 'idx_list_stats_cover'")->fetch();
    if (!$result) throw new Exception('missing');
} catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE lead_list_items ADD INDEX idx_list_user_created (list_id, user_id, created_at DESC)"); } catch (Exception $e2) {}
    try { $pdo->exec("ALTER TABLE lead_list_items ADD INDEX idx_list_user (list_id, user_id)"); } catch (Exception $e2) {}
    try { $pdo->exec("ALTER TABLE lead_list_items ADD INDEX idx_list_pipeline (list_id, pipeline_stage)"); } catch (Exception $e2) {}
    try { $pdo->exec("ALTER TABLE lead_list_items ADD INDEX idx_list_has_email (list_id, has_email)"); } catch (Exception $e2) {}
    try { $pdo->exec("ALTER TABLE lead_list_items ADD INDEX idx_list_stats_cover (list_id, user_id, status, pipeline_stage, visited_website, visited_social, reached_out, has_email, has_socials, has_phone, has_website, has_notes, outreach_email, outreach_instagram, follow_up_count)"); } catch (Exception $e2) {}
}

try {
    $pdo->query("SELECT 1 FROM ghl_connections LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE ghl_connections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) NOT NULL,
        api_key VARCHAR(255) NOT NULL,
        location_id VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    )");
    // Migrate existing credentials from users table
    $pdo->exec("INSERT INTO ghl_connections (user_id, name, api_key, location_id)
        SELECT id, 'My Free CRM Account', ghl_api_key, ghl_location_id
        FROM users WHERE ghl_api_key IS NOT NULL AND ghl_api_key != '' AND ghl_location_id IS NOT NULL AND ghl_location_id != ''");
}

try {
    $pdo->query("SELECT 1 FROM ghl_import_logs LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE ghl_import_logs (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        list_id INT NOT NULL,
        connection_id INT DEFAULT NULL,
        connection_name VARCHAR(255) DEFAULT NULL,
        status ENUM('pending','running','paused','completed','cancelled','failed') DEFAULT 'pending',
        total_contacts INT DEFAULT 0,
        imported INT DEFAULT 0,
        updated INT DEFAULT 0,
        failed INT DEFAULT 0,
        processed INT DEFAULT 0,
        tags JSON,
        workflow_id VARCHAR(100) DEFAULT NULL,
        workflow_name VARCHAR(255) DEFAULT NULL,
        drip_enabled TINYINT DEFAULT 0,
        drip_batch_size INT DEFAULT 0,
        drip_interval_minutes INT DEFAULT 0,
        drip_timezone VARCHAR(50) DEFAULT 'America/New_York',
        drip_send_hour INT DEFAULT NULL,
        drip_send_minute INT DEFAULT NULL,
        drip_next_batch_at DATETIME DEFAULT NULL,
        errors JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        INDEX idx_user (user_id),
        INDEX idx_list (list_id),
        INDEX idx_status (status),
        INDEX idx_drip_next (drip_next_batch_at, status)
    )");
}

try { $pdo->query("SELECT connection_id FROM ghl_import_logs LIMIT 1"); } catch (Exception $e) {
    try { $pdo->exec("ALTER TABLE ghl_import_logs ADD COLUMN connection_id INT DEFAULT NULL"); } catch (Exception $e2) {}
    try { $pdo->exec("ALTER TABLE ghl_import_logs ADD COLUMN connection_name VARCHAR(255) DEFAULT NULL"); } catch (Exception $e2) {}
}

try {
    $pdo->query("SELECT 1 FROM ghl_import_items LIMIT 1");
} catch (Exception $e) {
    $pdo->exec("CREATE TABLE ghl_import_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        import_log_id BIGINT NOT NULL,
        lead_id BIGINT NOT NULL,
        user_id INT NOT NULL,
        ghl_contact_id VARCHAR(100) DEFAULT NULL,
        status ENUM('pending','success','failed','skipped') DEFAULT 'pending',
        is_new TINYINT DEFAULT 0,
        error_message TEXT DEFAULT NULL,
        lead_data JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import (import_log_id),
        INDEX idx_lead (lead_id),
        INDEX idx_status (import_log_id, status)
    )");
}

    // Per-account walkthrough state, so the guided tour follows the account
    // across browsers/devices instead of a browser-local localStorage flag.
    try { $pdo->exec("ALTER TABLE users ADD COLUMN tour_seen TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // Background search queue — one row per (search, city). Workers claim pending
    // rows, call RapidAPI, ingest leads, and mark done. Lets the web request return
    // instantly instead of holding a worker for the RapidAPI round-trip.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS search_jobs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(40) NOT NULL,
            user_id INT NOT NULL,
            list_id INT NOT NULL,
            query VARCHAR(255) NOT NULL,
            city VARCHAR(120) NOT NULL,
            state_name VARCHAR(120) NOT NULL,
            per_city INT NOT NULL DEFAULT 20,
            status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            locked_by VARCHAR(80) NULL,
            results_found INT NOT NULL DEFAULT 0,
            inserted INT NOT NULL DEFAULT 0,
            skipped INT NOT NULL DEFAULT 0,
            skipped_no_credit INT NOT NULL DEFAULT 0,
            error VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            finished_at TIMESTAMP NULL,
            INDEX idx_claim (status, id),
            INDEX idx_batch (batch_id),
            INDEX idx_user_created (user_id, created_at)
        )");
    } catch (Exception $e) {}

    // All migrations above completed without throwing — record the flag so
    // subsequent requests skip the entire preflight block. If any migration
    // above had thrown a fatal, we'd never reach here and the block retries.
    @file_put_contents($schemaFlagFile, $schemaVersion);
}

// Has this account seen the guided walkthrough yet? (drives auto-start below)
$tourSeen = 0;
try {
    $tsStmt = $pdo->prepare("SELECT tour_seen FROM users WHERE id = ?");
    $tsStmt->execute([$userId]);
    $tourSeen = (int)$tsStmt->fetchColumn();
} catch (Exception $e) { $tourSeen = 0; }

// For the PAGE-RENDER path (not API calls): compute the login-promo flags now and
// RELEASE THE SESSION LOCK before the large HTML render below. PHP holds an
// exclusive per-user session lock for the whole request; the heavy render here
// was keeping it, so when several tabs/iframes load for the same user they
// serialize and each blocked request ties up a PHP-FPM worker — under load that
// exhausts the worker pool and the whole site times out. (API requests skip this;
// the action dispatch closes its own session.)
$showPennyPromo = false;
if (!isset($_GET['action'])) {
    if (!isset($_SESSION['tour_seen_at_login'])) { $_SESSION['tour_seen_at_login'] = $tourSeen; }
    $showPennyPromo = (!empty($_SESSION['tour_seen_at_login']) && empty($_SESSION['penny_promo_shown']));
    if ($showPennyPromo) { $_SESSION['penny_promo_shown'] = 1; }
    session_write_close();
}

$ghlConnsStmt = $pdo->prepare("SELECT * FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC");
$ghlConnsStmt->execute([$userId]);
$ghlConnections = $ghlConnsStmt->fetchAll(PDO::FETCH_ASSOC);
$ghlConnected = count($ghlConnections) > 0;

function importLeadCsvRows($pdo, $userId, $listId, $headers, $rows) {
    $headers = array_map(function($h) { return strtolower(trim((string)$h)); }, $headers);
    $col = function($name) use ($headers) {
        $idx = array_search(strtolower($name), $headers);
        return $idx === false ? null : $idx;
    };
    $socialPlatforms = ['Facebook','Instagram','Twitter','LinkedIn','YouTube','TikTok'];
    $prepared = [];
    $keys = [];
    $seen = [];

    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $get = function($name) use ($row, $col) {
            $i = $col($name);
            return $i === null ? '' : trim((string)($row[$i] ?? ''));
        };

        $businessName = $get('Business Name');
        $address = $get('Address');
        if ($businessName === '' && $address === '') continue;

        $key = strtolower($businessName . '|' . $address);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $keys[$key] = true;

        $emails = [];
        for ($i = 1; $i <= 5; $i++) {
            $e = $get("Email $i");
            if ($e !== '') $emails[] = $e;
        }

        $socials = [];
        foreach ($socialPlatforms as $p) {
            $u = $get($p);
            if ($u !== '') $socials[] = $u;
        }
        $other = $get('Other Socials');
        if ($other !== '') {
            foreach (preg_split('/[;,]\s*/', $other) as $u) {
                $u = trim($u);
                if ($u !== '') $socials[] = $u;
            }
        }

        $status = strtolower($get('Status'));
        if (!in_array($status, ['cold','warm','hot'])) $status = 'cold';

        $pipeline = strtolower($get('Pipeline Stage'));
        if (!in_array($pipeline, ['new','contacted','engaged','client','no_response'])) $pipeline = 'new';

        $phone = $get('Phone');
        $website = $get('Website');
        $notes = $get('Notes');
        $rating = $get('Rating');
        $reviews = $get('Reviews');

        $prepared[] = [
            'key' => $key,
            'values' => [
                $listId, $userId,
                $businessName,
                $address,
                $get('City'),
                $get('State'),
                $phone,
                $website,
                $rating !== '' ? floatval($rating) : null,
                $reviews !== '' ? intval($reviews) : 0,
                $status,
                $pipeline,
                $notes,
                json_encode($emails),
                json_encode($socials),
                !empty($emails) ? 1 : 0,
                !empty($socials) ? 1 : 0,
                $phone !== '' ? 1 : 0,
                $website !== '' ? 1 : 0,
                $notes !== '' ? 1 : 0
            ]
        ];
    }

    $existing = [];
    $keyList = array_keys($keys);
    if (!empty($keyList)) {
        $placeholders = implode(',', array_fill(0, count($keyList), '?'));
        $chk = $pdo->prepare("SELECT LOWER(CONCAT(COALESCE(business_name,''),'|',COALESCE(address,''))) AS k FROM lead_list_items WHERE list_id = ? AND user_id = ? AND LOWER(CONCAT(COALESCE(business_name,''),'|',COALESCE(address,''))) IN ($placeholders)");
        $chk->execute(array_merge([$listId, $userId], $keyList));
        while ($r = $chk->fetch(PDO::FETCH_ASSOC)) { $existing[$r['k']] = true; }
    }

    $stmt = $pdo->prepare("
        INSERT INTO lead_list_items
        (list_id, user_id, business_name, address, city, state, phone, website,
         rating, review_count, status, pipeline_stage, notes, emails, social_media_links,
         has_email, has_socials, has_phone, has_website, has_notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $inserted = 0; $skipped = count($rows) - count($prepared); $errors = 0;
    try { $pdo->beginTransaction(); } catch (Exception $e) {}
    foreach ($prepared as $item) {
        if (isset($existing[$item['key']])) { $skipped++; continue; }
        try {
            $stmt->execute($item['values']);
            $existing[$item['key']] = true;
            $inserted++;
        } catch (Exception $e) {
            $errors++;
        }
    }
    try {
        if ($pdo->inTransaction()) $pdo->commit();
    } catch (Exception $e) {}

    return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
}

// API Endpoints
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    // Release the session lock before any slow work (external enrichment/GHL
    // curls below run up to 30s). Holding it serializes every same-user request
    // behind this one, freezing navigation during a search. No API action writes
    // to $_SESSION (only the page-render path does), so this is safe.
    session_write_close();
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($_GET['action']) {

        case 'getLists':
            try {
                $hasClaimsTable = false;
                try { $pdo->query("SELECT 1 FROM list_claims LIMIT 1"); $hasClaimsTable = true; } catch (Exception $e) {}
                $claimsJoin = $hasClaimsTable ? "LEFT JOIN (SELECT source_list_id, COUNT(*) as cnt FROM list_claims GROUP BY source_list_id) cl ON cl.source_list_id = l.id" : "";
                $claimsCol = $hasClaimsTable ? "COALESCE(cl.cnt, 0)" : "0";
                $stmt = $pdo->prepare("
                    SELECT l.*,
                        COALESCE(li.lead_count, 0) as lead_count,
                        COALESCE(li.visited_count, 0) as visited_count,
                        COALESCE(li.reached_out_count, 0) as reached_out_count,
                        COALESCE(li.stage_new, 0) as stage_new,
                        COALESCE(li.stage_contacted, 0) as stage_contacted,
                        COALESCE(li.engaged_count, 0) as engaged_count,
                        COALESCE(li.client_count, 0) as client_count,
                        COALESCE(li.stage_no_response, 0) as stage_no_response,
                        COALESCE(ls.cities_searched, 0) as cities_searched,
                        $claimsCol as claim_count
                    FROM lead_lists l
                    LEFT JOIN (
                        SELECT list_id,
                            COUNT(*) as lead_count,
                            SUM(visited_website = 1) as visited_count,
                            SUM(reached_out = 1) as reached_out_count,
                            SUM(pipeline_stage = 'new' OR pipeline_stage IS NULL) as stage_new,
                            SUM(pipeline_stage = 'contacted') as stage_contacted,
                            SUM(pipeline_stage = 'engaged') as engaged_count,
                            SUM(pipeline_stage = 'client') as client_count,
                            SUM(pipeline_stage = 'no_response') as stage_no_response
                        FROM lead_list_items WHERE user_id = ? GROUP BY list_id
                    ) li ON li.list_id = l.id
                    LEFT JOIN (
                        SELECT list_id, COUNT(DISTINCT CONCAT(COALESCE(city,''), COALESCE(state_name,''))) as cities_searched
                        FROM lead_list_searches WHERE user_id = ? GROUP BY list_id
                    ) ls ON ls.list_id = l.id
                    $claimsJoin
                    WHERE l.user_id = ? ORDER BY l.updated_at DESC
                ");
                // Bind order matches the ?s in source: li subquery, ls subquery, outer WHERE.
                $stmt->execute([$userId, $userId, $userId]);
                echo json_encode(['success' => true, 'lists' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'createList':
            $name = trim($input['name'] ?? '');
            if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required']); exit; }
            $stmt = $pdo->prepare("INSERT INTO lead_lists (user_id, name, description) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $name, $input['description'] ?? '']);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;

        case 'markTourSeen':
            try { $pdo->prepare("UPDATE users SET tour_seen = 1 WHERE id = ?")->execute([$userId]); } catch (Exception $e) {}
            echo json_encode(['success' => true]);
            exit;

        case 'updateList':
            $stmt = $pdo->prepare("UPDATE lead_lists SET name = ?, description = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$input['name'], $input['description'] ?? '', $input['id'], $userId]);
            echo json_encode(['success' => true]);
            exit;

        case 'deleteList':
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM lead_list_items WHERE list_id = ? AND user_id = ?")->execute([$input['id'], $userId]);
                $pdo->prepare("DELETE FROM lead_list_searches WHERE list_id = ? AND user_id = ?")->execute([$input['id'], $userId]);
                $pdo->prepare("DELETE FROM lead_lists WHERE id = ? AND user_id = ?")->execute([$input['id'], $userId]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

        case 'togglePublic':
            $listId = $input['id'] ?? 0;
            $makePublic = $input['is_public'] ?? 0;
            if ($makePublic) {
                $token = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE lead_lists SET is_public = 1, public_token = ? WHERE id = ? AND user_id = ?")
                    ->execute([$token, $listId, $userId]);
                echo json_encode(['success' => true, 'is_public' => 1, 'public_token' => $token]);
            } else {
                $pdo->prepare("UPDATE lead_lists SET is_public = 0, public_token = NULL WHERE id = ? AND user_id = ?")
                    ->execute([$listId, $userId]);
                echo json_encode(['success' => true, 'is_public' => 0, 'public_token' => null]);
            }
            exit;

        case 'getListClaims':
            $listId = $_GET['list_id'] ?? 0;
            try {
                $pdo->query("SELECT 1 FROM list_claims LIMIT 1");
            } catch (Exception $e) {
                echo json_encode(['success' => true, 'claims' => []]);
                exit;
            }
            $claimStmt = $pdo->prepare("SELECT claimed_user_name, claimed_user_email, claim_type, created_at FROM list_claims WHERE source_list_id = ? AND owner_user_id = ? ORDER BY created_at DESC");
            $claimStmt->execute([$listId, $userId]);
            echo json_encode(['success' => true, 'claims' => $claimStmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'getListDetail':
            $listId = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM lead_lists WHERE id = ? AND user_id = ?");
            $stmt->execute([$listId, $userId]);
            $list = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$list) { echo json_encode(['success' => false, 'error' => 'Not found']); exit; }

            $stats = $pdo->prepare("
                SELECT
                    COUNT(*) as total,
                    SUM(status = 'cold') as cold,
                    SUM(status = 'warm') as warm,
                    SUM(status = 'hot') as hot,
                    SUM(visited_website) as websites_visited,
                    SUM(visited_social) as socials_visited,
                    SUM(reached_out) as reached_out_count,
                    SUM(has_website) as has_website,
                    SUM(has_phone) as has_phone,
                    SUM(has_email) as has_email,
                    SUM(has_socials) as has_socials,
                    SUM(has_notes) as has_notes,
                    SUM(outreach_email) as emailed_count,
                    SUM(outreach_instagram) as ig_dm_count,
                    SUM(COALESCE(pipeline_stage,'new') = 'new') as stage_new,
                    SUM(pipeline_stage = 'contacted') as stage_contacted,
                    SUM(pipeline_stage = 'engaged') as stage_engaged,
                    SUM(pipeline_stage = 'client') as stage_client,
                    SUM(pipeline_stage = 'no_response') as stage_no_response,
                    AVG(follow_up_count) as avg_follow_ups
                FROM lead_list_items WHERE list_id = ? AND user_id = ?
            ");
            $stats->execute([$listId, $userId]);
            $list['stats'] = $stats->fetch(PDO::FETCH_ASSOC);

            $searches = $pdo->prepare("
                SELECT state_name, city, search_query, results_count, created_at
                FROM lead_list_searches WHERE list_id = ? ORDER BY created_at DESC
            ");
            $searches->execute([$listId]);
            $list['searches'] = $searches->fetchAll(PDO::FETCH_ASSOC);

            $stateStats = $pdo->prepare("
                SELECT state_name, COUNT(DISTINCT city) as cities_searched
                FROM lead_list_searches WHERE list_id = ? GROUP BY state_name ORDER BY state_name
            ");
            $stateStats->execute([$listId]);
            $list['state_stats'] = $stateStats->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'list' => $list]);
            exit;

        case 'getLeads':
            $listId = $_GET['list_id'] ?? ($input['list_id'] ?? 0);
            $page = max(1, intval($_GET['page'] ?? ($input['page'] ?? 1)));
            $perPage = min(500, max(10, intval($_GET['per_page'] ?? ($input['per_page'] ?? 50))));
            $search = $_GET['search'] ?? ($input['search'] ?? '');
            $status = $_GET['status'] ?? ($input['status'] ?? '');
            $offset = ($page - 1) * $perPage;

            $where = "list_id = ? AND user_id = ?";
            $params = [$listId, $userId];

            if ($search) {
                $where .= " AND (business_name LIKE ? OR address LIKE ? OR phone LIKE ? OR notes LIKE ?)";
                $s = "%$search%";
                $params = array_merge($params, [$s, $s, $s, $s]);
            }
            if ($status && in_array($status, ['cold', 'warm', 'hot'])) {
                $where .= " AND status = ?";
                $params[] = $status;
            }
            $has = $_GET['has'] ?? ($input['has'] ?? '');
            if ($has === 'email') {
                $where .= " AND has_email = 1";
            } elseif ($has === 'phone') {
                $where .= " AND has_phone = 1";
            } elseif ($has === 'socials') {
                $where .= " AND has_socials = 1";
            } elseif ($has === 'notes') {
                $where .= " AND has_notes = 1";
            } elseif ($has === 'visited') {
                $where .= " AND visited_website = 1";
            } elseif ($has === 'reached_out') {
                $where .= " AND reached_out = 1";
            } elseif ($has === 'emailed') {
                $where .= " AND outreach_email = 1";
            } elseif ($has === 'ig_dm') {
                $where .= " AND outreach_instagram = 1";
            } elseif ($has === 'stage_new') {
                $where .= " AND (pipeline_stage = 'new' OR pipeline_stage IS NULL)";
            } elseif ($has === 'stage_contacted') {
                $where .= " AND pipeline_stage = 'contacted'";
            } elseif ($has === 'stage_engaged') {
                $where .= " AND pipeline_stage = 'engaged'";
            } elseif ($has === 'stage_client') {
                $where .= " AND pipeline_stage = 'client'";
            } elseif ($has === 'stage_no_response') {
                $where .= " AND pipeline_stage = 'no_response'";
            } elseif ($has === 'selected') {
                $selectedIds = $input['selected_ids'] ?? [];
                if (!empty($selectedIds)) {
                    $selectedIds = array_map('intval', $selectedIds);
                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                    $where .= " AND id IN ($placeholders)";
                    $params = array_merge($params, $selectedIds);
                } else {
                    $where .= " AND 1=0";
                }
            }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lead_list_items WHERE $where");
            $countStmt->execute($params);
            $total = $countStmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT * FROM lead_list_items WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($leads as &$lead) {
                $lead['emails'] = json_decode($lead['emails'] ?: '[]', true);
                $lead['social_media_links'] = json_decode($lead['social_media_links'] ?: '[]', true);
                $lead['visited_socials'] = json_decode($lead['visited_socials'] ?: '[]', true);
            }

            echo json_encode([
                'success' => true,
                'leads' => $leads,
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ]);
            exit;

        case 'getMapLeads':
            $listId = (int)($_GET['list_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, business_name, address as full_address, phone, website, latitude, longitude, rating, review_count, types, emails, social_media_links, visited_website, reached_out, city, state, notes FROM lead_list_items WHERE list_id = ? AND user_id = ? AND latitude IS NOT NULL AND latitude != '' AND longitude IS NOT NULL AND longitude != ''");
            $stmt->execute([$listId, $userId]);
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leads as &$lead) {
                $lead['emails'] = json_decode($lead['emails'] ?: '[]', true);
                $lead['social_media_links'] = json_decode($lead['social_media_links'] ?: '[]', true);
            }
            echo json_encode(['success' => true, 'leads' => $leads]);
            exit;

        case 'addLeads':
            $listId = $input['list_id'] ?? 0;
            $leads = $input['leads'] ?? [];
            if (!$listId || empty($leads)) {
                echo json_encode(['success' => false, 'error' => 'Missing data']);
                exit;
            }

            // Shared with the background search worker — one billing implementation.
            $res = ingestLeads($pdo, (int)$userId, (int)$listId, $leads);

            $pdo->prepare("UPDATE lead_lists SET updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$listId, $userId]);
            echo json_encode([
                'success' => true,
                'inserted' => $res['inserted'],
                'skipped' => $res['skipped'],
                'skipped_no_credit' => $res['skipped_no_credit'],
                'credits' => currentCredits($pdo, $userId)
            ]);
            exit;

        case 'enqueueSearch':
            $listId = (int)($input['list_id'] ?? 0);
            $query = trim($input['query'] ?? '');
            $perCity = max(1, min(500, (int)($input['per_city'] ?? 20)));
            $cities = $input['cities'] ?? [];   // [{city, state_name}, ...]
            if (!$listId || $query === '' || empty($cities)) {
                echo json_encode(['success' => false, 'error' => 'Missing data']); exit;
            }
            $own = $pdo->prepare("SELECT id FROM lead_lists WHERE id = ? AND user_id = ?");
            $own->execute([$listId, $userId]);
            if (!$own->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'List not found']); exit; }
            if (currentCredits($pdo, $userId) < 1) { echo json_encode(['success' => false, 'out_of_credits' => true, 'error' => 'out_of_credits']); exit; }

            $batchId = bin2hex(random_bytes(12));
            $rows = [];
            $vals = [];
            foreach ($cities as $c) {
                $cityName = trim($c['city'] ?? '');
                $stateName = trim($c['state_name'] ?? '');
                if ($cityName === '') continue;
                $rows[] = '(?,?,?,?,?,?,?)';
                array_push($vals, $batchId, $userId, $listId, $query, $cityName, $stateName, $perCity);
            }
            if (empty($rows)) { echo json_encode(['success' => false, 'error' => 'No valid cities']); exit; }
            $pdo->prepare("INSERT INTO search_jobs (batch_id, user_id, list_id, query, city, state_name, per_city) VALUES " . implode(',', $rows))->execute($vals);
            echo json_encode(['success' => true, 'batch_id' => $batchId, 'total' => count($rows)]);
            exit;

        case 'searchProgress':
            $batchId = $input['batch_id'] ?? ($_GET['batch_id'] ?? '');
            if (!$batchId) { echo json_encode(['success' => false, 'error' => 'Missing batch_id']); exit; }
            $st = $pdo->prepare("
                SELECT COUNT(*) as total,
                    SUM(status='pending') as pending,
                    SUM(status='processing') as processing,
                    SUM(status='done') as done,
                    SUM(status='failed') as failed,
                    COALESCE(SUM(results_found),0) as found,
                    COALESCE(SUM(inserted),0) as inserted,
                    COALESCE(SUM(skipped_no_credit),0) as skipped_no_credit
                FROM search_jobs WHERE batch_id = ? AND user_id = ?
            ");
            $st->execute([$batchId, $userId]);
            $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int)($p['total'] ?? 0);
            $finished = (int)($p['done'] ?? 0) + (int)($p['failed'] ?? 0);
            echo json_encode([
                'success' => true,
                'total' => $total,
                'pending' => (int)($p['pending'] ?? 0),
                'processing' => (int)($p['processing'] ?? 0),
                'done' => (int)($p['done'] ?? 0),
                'failed' => (int)($p['failed'] ?? 0),
                'found' => (int)($p['found'] ?? 0),
                'inserted' => (int)($p['inserted'] ?? 0),
                'skipped_no_credit' => (int)($p['skipped_no_credit'] ?? 0),
                'complete' => ($total > 0 && $finished >= $total),
                'credits' => currentCredits($pdo, $userId)
            ]);
            exit;

        case 'updateLead':
            $id = $input['id'] ?? 0;
            $allowed = ['notes', 'status', 'visited_website', 'visited_social', 'reached_out', 'emails', 'social_media_links', 'visited_socials', 'pipeline_stage', 'outreach_email', 'outreach_instagram', 'follow_up_count'];
            $sets = [];
            $params = [];

            foreach ($allowed as $field) {
                if (isset($input[$field])) {
                    if (in_array($field, ['emails', 'social_media_links', 'visited_socials'])) {
                        $sets[] = "$field = ?";
                        $params[] = json_encode($input[$field]);
                        if ($field === 'emails') {
                            $sets[] = "has_email = ?";
                            $params[] = !empty($input[$field]) ? 1 : 0;
                        } elseif ($field === 'social_media_links') {
                            $sets[] = "has_socials = ?";
                            $params[] = !empty($input[$field]) ? 1 : 0;
                        }
                    } else {
                        $sets[] = "$field = ?";
                        $params[] = $input[$field];
                        if ($field === 'notes') {
                            $sets[] = "has_notes = ?";
                            $params[] = ($input[$field] !== null && $input[$field] !== '') ? 1 : 0;
                        }
                    }
                }
            }

            if (isset($input['visited_website']) && $input['visited_website']) {
                $sets[] = "website_visited_at = NOW()";
            }
            if (isset($input['visited_social']) && $input['visited_social']) {
                $sets[] = "social_visited_at = NOW()";
            }
            if (isset($input['reached_out']) && $input['reached_out']) {
                $sets[] = "reached_out_at = NOW()";
            }
            if ((isset($input['outreach_email']) && $input['outreach_email']) || (isset($input['outreach_instagram']) && $input['outreach_instagram'])) {
                $sets[] = "first_contacted_at = COALESCE(first_contacted_at, NOW())";
            }
            if (isset($input['follow_up_count']) && $input['follow_up_count'] > 0) {
                $sets[] = "last_follow_up_at = NOW()";
            }

            // Auto-calculate status
            if (isset($input['visited_website']) || isset($input['visited_social']) || isset($input['reached_out'])) {
                $checkStmt = $pdo->prepare("SELECT visited_website, visited_social, reached_out FROM lead_list_items WHERE id = ? AND user_id = ?");
                $checkStmt->execute([$id, $userId]);
                $current = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($current) {
                    $vw = $input['visited_website'] ?? $current['visited_website'];
                    $vs = $input['visited_social'] ?? $current['visited_social'];
                    $ro = $input['reached_out'] ?? $current['reached_out'];
                    if ($ro) {
                        $sets[] = "status = 'hot'";
                    } elseif ($vw || $vs) {
                        $sets[] = "status = 'warm'";
                    } else {
                        $sets[] = "status = 'cold'";
                    }
                }
            }

            if (empty($sets)) { echo json_encode(['success' => false, 'error' => 'No fields']); exit; }

            $params[] = $id;
            $params[] = $userId;
            $stmt = $pdo->prepare("UPDATE lead_list_items SET " . implode(', ', $sets) . " WHERE id = ? AND user_id = ?");
            $stmt->execute($params);
            echo json_encode(['success' => true]);
            exit;

        case 'deleteLead':
            $pdo->prepare("DELETE FROM lead_list_items WHERE id = ? AND user_id = ?")->execute([$input['id'], $userId]);
            echo json_encode(['success' => true]);
            exit;

        case 'deleteLeads':
            $ids = $input['ids'] ?? [];
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge($ids, [$userId]);
                $pdo->prepare("DELETE FROM lead_list_items WHERE id IN ($placeholders) AND user_id = ?")->execute($params);
            }
            echo json_encode(['success' => true]);
            exit;

        case 'bulkUpdateLeads':
            $ids = $input['ids'] ?? [];
            $field = $input['field'] ?? '';
            $value = $input['value'] ?? 0;
            $allowedFields = ['visited_website', 'reached_out', 'outreach_email', 'outreach_instagram', 'pipeline_stage'];
            if (!empty($ids) && in_array($field, $allowedFields)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge([$value], $ids, [$userId]);
                $pdo->prepare("UPDATE lead_list_items SET $field = ? WHERE id IN ($placeholders) AND user_id = ?")->execute($params);
                if (in_array($field, ['outreach_email', 'outreach_instagram']) && $value) {
                    $pdo->prepare("UPDATE lead_list_items SET first_contacted_at = COALESCE(first_contacted_at, NOW()) WHERE id IN ($placeholders) AND user_id = ?")->execute(array_merge($ids, [$userId]));
                }
            }
            echo json_encode(['success' => true]);
            exit;

        case 'logSearch':
            $stmt = $pdo->prepare("INSERT INTO lead_list_searches (list_id, user_id, search_query, state_name, city, results_count) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$input['list_id'], $userId, $input['search_query'] ?? '', $input['state_name'] ?? '', $input['city'] ?? '', $input['results_count'] ?? 0]);
            echo json_encode(['success' => true]);
            exit;

        case 'getStates':
            $country = $_GET['country'] ?? 'US';
            if ($country === 'UK') {
                try {
                    $stmt = $pdo->query("SELECT DISTINCT admin_name AS state_name, admin_code AS state_id, country_name FROM ukcities ORDER BY admin_name");
                    echo json_encode(['success' => true, 'states' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => true, 'states' => []]);
                }
            } elseif ($country === 'EU') {
                try {
                    $stmt = $pdo->query("SELECT DISTINCT country AS state_name, country AS state_id FROM european_cities_towns_44_countries_hd2data ORDER BY country");
                    echo json_encode(['success' => true, 'states' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    echo json_encode(['success' => true, 'states' => []]);
                }
            } else {
                try {
                    $stmt = $pdo->query("SELECT DISTINCT state AS state_name, state AS state_id FROM usa_all_states_cities_full_hd2data ORDER BY state");
                    echo json_encode(['success' => true, 'states' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (Exception $e) {
                    $stmt = $pdo->query("SELECT DISTINCT state_id, state_name FROM locations ORDER BY state_name");
                    echo json_encode(['success' => true, 'states' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
                }
            }
            exit;

        case 'getCities':
            $stateId = $_GET['state_id'] ?? '';
            $country = $_GET['country'] ?? 'US';
            if ($country === 'UK') {
                $stmt = $pdo->prepare("SELECT DISTINCT city, NULL as population FROM ukcities WHERE admin_code = ? ORDER BY city");
            } elseif ($country === 'EU') {
                try {
                    $stmt = $pdo->prepare("SELECT DISTINCT city_or_town AS city, population FROM european_cities_towns_44_countries_hd2data WHERE country = ? ORDER BY city_or_town");
                } catch (Exception $e) {
                    $stmt = $pdo->prepare("SELECT DISTINCT city, NULL as population FROM european_cities WHERE admin_code = ? ORDER BY city");
                }
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT DISTINCT city_or_town AS city, population FROM usa_all_states_cities_full_hd2data WHERE state = ? ORDER BY city_or_town");
                } catch (Exception $e) {
                    $stmt = $pdo->prepare("SELECT DISTINCT city, NULL as population FROM locations WHERE state_id = ? ORDER BY city");
                }
            }
            $stmt->execute([$stateId]);
            echo json_encode(['success' => true, 'cities' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'exportLeads':
            $listId = $_GET['list_id'] ?? 0;
            $page = max(1, intval($_GET['page'] ?? 1));
            $perPage = min(200, max(10, intval($_GET['per_page'] ?? 50)));
            $status = $_GET['status'] ?? '';
            $has = $_GET['has'] ?? '';
            $search = $_GET['search'] ?? '';
            $pipeline = $_GET['pipeline'] ?? '';
            $where = "list_id = ? AND user_id = ?";
            $params = [$listId, $userId];
            if ($status && in_array($status, ['cold', 'warm', 'hot'])) { $where .= " AND status = ?"; $params[] = $status; }
            if ($has === 'email') { $where .= " AND has_email = 1"; }
            elseif ($has === 'phone') { $where .= " AND has_phone = 1"; }
            elseif ($has === 'socials') { $where .= " AND has_socials = 1"; }
            elseif ($has === 'notes') { $where .= " AND has_notes = 1"; }
            elseif ($has === 'visited') { $where .= " AND visited_website = 1"; }
            elseif ($has === 'reached_out') { $where .= " AND reached_out = 1"; }
            elseif ($has === 'emailed') { $where .= " AND outreach_email = 1"; }
            elseif ($has === 'ig_dm') { $where .= " AND outreach_instagram = 1"; }
            elseif ($has === 'has_website') { $where .= " AND has_website = 1"; }
            if ($pipeline && in_array($pipeline, ['new','contacted','engaged','client','no_response'])) {
                if ($pipeline === 'new') { $where .= " AND (pipeline_stage = 'new' OR pipeline_stage IS NULL)"; }
                else { $where .= " AND pipeline_stage = ?"; $params[] = $pipeline; }
            }
            if ($search) { $where .= " AND (business_name LIKE ? OR address LIKE ? OR notes LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lead_list_items WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $stmt = $pdo->prepare("SELECT * FROM lead_list_items WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
            $stmt->execute($params);
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($leads as &$lead) {
                $lead['emails'] = json_decode($lead['emails'] ?: '[]', true);
                $lead['social_media_links'] = json_decode($lead['social_media_links'] ?: '[]', true);
                $lead['visited_socials'] = json_decode($lead['visited_socials'] ?: '[]', true);
            }
            echo json_encode(['success' => true, 'leads' => $leads, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'total_pages' => max(1, ceil($total / $perPage))]);
            exit;

        case 'exportCount':
            $listId = $_GET['list_id'] ?? 0;
            $status = $_GET['status'] ?? '';
            $has = $_GET['has'] ?? '';
            $search = $_GET['search'] ?? '';
            $pipeline = $_GET['pipeline'] ?? '';
            $where = "list_id = ? AND user_id = ?";
            $params = [$listId, $userId];
            if ($status && in_array($status, ['cold', 'warm', 'hot'])) { $where .= " AND status = ?"; $params[] = $status; }
            if ($has === 'email') { $where .= " AND has_email = 1"; }
            elseif ($has === 'phone') { $where .= " AND has_phone = 1"; }
            elseif ($has === 'socials') { $where .= " AND has_socials = 1"; }
            elseif ($has === 'notes') { $where .= " AND has_notes = 1"; }
            elseif ($has === 'visited') { $where .= " AND visited_website = 1"; }
            elseif ($has === 'reached_out') { $where .= " AND reached_out = 1"; }
            elseif ($has === 'emailed') { $where .= " AND outreach_email = 1"; }
            elseif ($has === 'ig_dm') { $where .= " AND outreach_instagram = 1"; }
            elseif ($has === 'has_website') { $where .= " AND has_website = 1"; }
            if ($pipeline && in_array($pipeline, ['new','contacted','engaged','client','no_response'])) {
                if ($pipeline === 'new') { $where .= " AND (pipeline_stage = 'new' OR pipeline_stage IS NULL)"; }
                else { $where .= " AND pipeline_stage = ?"; $params[] = $pipeline; }
            }
            if ($search) { $where .= " AND (business_name LIKE ? OR address LIKE ? OR notes LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

            $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(has_email) as with_email, SUM(has_phone) as with_phone, SUM(has_socials) as with_socials, SUM(has_website) as with_website, SUM(status='cold') as cold, SUM(status='warm') as warm, SUM(status='hot') as hot FROM lead_list_items WHERE $where");
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'total' => (int)$row['total'], 'with_email' => (int)$row['with_email'], 'with_phone' => (int)$row['with_phone'], 'with_socials' => (int)$row['with_socials'], 'with_website' => (int)$row['with_website'], 'cold' => (int)$row['cold'], 'warm' => (int)$row['warm'], 'hot' => (int)$row['hot']]);
            exit;

        case 'exportCSV':
            $listId = $_GET['list_id'] ?? 0;
            $status = $_GET['status'] ?? '';
            $has = $_GET['has'] ?? '';
            $search = $_GET['search'] ?? '';
            $pipeline = $_GET['pipeline'] ?? '';
            $where = "list_id = ? AND user_id = ?";
            $params = [$listId, $userId];
            if ($status && in_array($status, ['cold', 'warm', 'hot'])) { $where .= " AND status = ?"; $params[] = $status; }
            if ($has === 'email') { $where .= " AND has_email = 1"; }
            elseif ($has === 'phone') { $where .= " AND has_phone = 1"; }
            elseif ($has === 'socials') { $where .= " AND has_socials = 1"; }
            elseif ($has === 'notes') { $where .= " AND has_notes = 1"; }
            elseif ($has === 'visited') { $where .= " AND visited_website = 1"; }
            elseif ($has === 'reached_out') { $where .= " AND reached_out = 1"; }
            elseif ($has === 'emailed') { $where .= " AND outreach_email = 1"; }
            elseif ($has === 'ig_dm') { $where .= " AND outreach_instagram = 1"; }
            elseif ($has === 'has_website') { $where .= " AND has_website = 1"; }
            if ($pipeline && in_array($pipeline, ['new','contacted','engaged','client','no_response'])) {
                if ($pipeline === 'new') { $where .= " AND (pipeline_stage = 'new' OR pipeline_stage IS NULL)"; }
                else { $where .= " AND pipeline_stage = ?"; $params[] = $pipeline; }
            }
            if ($search) { $where .= " AND (business_name LIKE ? OR address LIKE ? OR notes LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lead_list_items WHERE $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            if ($total === 0) { header('HTTP/1.1 404 Not Found'); echo 'No leads match your filters.'; exit; }

            $listStmt = $pdo->prepare("SELECT name FROM lead_lists WHERE id = ? AND user_id = ?");
            $listStmt->execute([$listId, $userId]);
            $listName = preg_replace('/[^a-z0-9]/i', '_', $listStmt->fetchColumn() ?: 'leads');
            $dateStr = date('Y-m-d');

            $platforms = [
                'Facebook' => ['facebook.com','fb.com','fb.me'],
                'Instagram' => ['instagram.com','instagr.am'],
                'Twitter' => ['twitter.com','x.com'],
                'LinkedIn' => ['linkedin.com'],
                'YouTube' => ['youtube.com','youtu.be'],
                'TikTok' => ['tiktok.com']
            ];
            $csvHeaders = ['Business Name','Address','City','State','Phone','Website','Rating','Reviews','Status','Pipeline Stage','Notes','Email 1','Email 2','Email 3','Email 4','Email 5'];
            foreach (array_keys($platforms) as $p) $csvHeaders[] = $p;
            $csvHeaders[] = 'Other Socials';

            $maxPerFile = 500000;
            $chunkSize = 5000;

            $writeCsvRows = function($output, $offset, $limit) use ($pdo, $where, $params, $platforms) {
                $written = 0;
                $pos = $offset;
                $chunkSize = 5000;
                while ($written < $limit) {
                    $fetch = min($chunkSize, $limit - $written);
                    $stmt = $pdo->prepare("SELECT business_name, address, city, state, phone, website, rating, review_count, status, pipeline_stage, notes, emails, social_media_links FROM lead_list_items WHERE $where ORDER BY created_at DESC LIMIT $fetch OFFSET $pos");
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($rows)) break;
                    foreach ($rows as $r) {
                        $emails = json_decode($r['emails'] ?: '[]', true);
                        $socials = json_decode($r['social_media_links'] ?: '[]', true);
                        $categorized = array_fill_keys(array_keys($platforms), '');
                        $other = [];
                        foreach ($socials as $url) {
                            $matched = false;
                            foreach ($platforms as $pName => $patterns) {
                                foreach ($patterns as $pat) {
                                    if (stripos($url, $pat) !== false) { if (!$categorized[$pName]) $categorized[$pName] = $url; $matched = true; break 2; }
                                }
                            }
                            if (!$matched) $other[] = $url;
                        }
                        $row = [$r['business_name'],$r['address'],$r['city'],$r['state'],$r['phone'],$r['website'],$r['rating'],$r['review_count'],$r['status'],$r['pipeline_stage'] ?? 'new',$r['notes']];
                        for ($i = 0; $i < 5; $i++) $row[] = $emails[$i] ?? '';
                        foreach (array_keys($platforms) as $p) $row[] = $categorized[$p];
                        $row[] = implode('; ', $other);
                        fputcsv($output, $row);
                        $written++;
                    }
                    $pos += count($rows);
                }
                return $written;
            };

            if ($total <= $maxPerFile) {
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"{$listName}_{$dateStr}.csv\"");
                header('Cache-Control: no-cache');
                $output = fopen('php://output', 'w');
                fprintf($output, "\xEF\xBB\xBF");
                fputcsv($output, $csvHeaders);
                $writeCsvRows($output, 0, $total);
                fclose($output);
            } else {
                $tmpDir = sys_get_temp_dir();
                $zipPath = tempnam($tmpDir, 'leads_zip_');
                $zip = new ZipArchive();
                $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $tmpFiles = [];
                $fileNum = 0;
                $remaining = $total;
                $offset = 0;
                while ($remaining > 0) {
                    $fileNum++;
                    $count = min($maxPerFile, $remaining);
                    $csvPath = tempnam($tmpDir, 'leads_csv_');
                    $tmpFiles[] = $csvPath;
                    $csvFile = fopen($csvPath, 'w');
                    fprintf($csvFile, "\xEF\xBB\xBF");
                    fputcsv($csvFile, $csvHeaders);
                    $writeCsvRows($csvFile, $offset, $count);
                    fclose($csvFile);
                    $zip->addFile($csvPath, "{$listName}_part{$fileNum}.csv");
                    $offset += $count;
                    $remaining -= $count;
                }
                $zip->close();
                header('Content-Type: application/zip');
                header("Content-Disposition: attachment; filename=\"{$listName}_{$dateStr}.zip\"");
                header('Content-Length: ' . filesize($zipPath));
                header('Cache-Control: no-cache');
                readfile($zipPath);
                foreach ($tmpFiles as $f) @unlink($f);
                @unlink($zipPath);
            }
            exit;

        case 'importCSV':
            $listId = intval($_POST['list_id'] ?? 0);
            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            $own = $pdo->prepare("SELECT id FROM lead_lists WHERE id = ? AND user_id = ?");
            $own->execute([$listId, $userId]);
            if (!$own->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'List not found']); exit; }

            if (empty($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'No CSV file uploaded']); exit;
            }

            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$fh) { echo json_encode(['success' => false, 'error' => 'Could not read file']); exit; }

            $first = fgets($fh);
            if ($first === false) { fclose($fh); echo json_encode(['success' => false, 'error' => 'Empty file']); exit; }
            if (substr($first, 0, 3) === "\xEF\xBB\xBF") $first = substr($first, 3);
            rewind($fh);
            if (substr(fread($fh, 3), 0, 3) !== "\xEF\xBB\xBF") rewind($fh);

            $headerRow = fgetcsv($fh);
            if (!$headerRow) { fclose($fh); echo json_encode(['success' => false, 'error' => 'Missing header row']); exit; }
            $headers = array_map(function($h) { return strtolower(trim($h)); }, $headerRow);

            $col = function($name) use ($headers) {
                $idx = array_search(strtolower($name), $headers);
                return $idx === false ? null : $idx;
            };

            $socialPlatforms = ['Facebook','Instagram','Twitter','LinkedIn','YouTube','TikTok'];

            $existing = [];
            $chk = $pdo->prepare("SELECT LOWER(CONCAT(COALESCE(business_name,''),'|',COALESCE(address,''))) AS k FROM lead_list_items WHERE list_id = ? AND user_id = ?");
            $chk->execute([$listId, $userId]);
            while ($r = $chk->fetch(PDO::FETCH_ASSOC)) { $existing[$r['k']] = true; }

            $stmt = $pdo->prepare("
                INSERT INTO lead_list_items
                (list_id, user_id, business_name, address, city, state, phone, website,
                 rating, review_count, status, pipeline_stage, notes, emails, social_media_links,
                 has_email, has_socials, has_phone, has_website, has_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $inserted = 0; $skipped = 0; $errors = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) === 1 && trim($row[0]) === '') continue;

                $get = function($name) use ($row, $col) {
                    $i = $col($name);
                    return $i === null ? '' : trim($row[$i] ?? '');
                };

                $businessName = $get('Business Name');
                $address = $get('Address');
                if ($businessName === '' && $address === '') { $skipped++; continue; }

                $key = strtolower($businessName . '|' . $address);
                if (isset($existing[$key])) { $skipped++; continue; }
                $existing[$key] = true;

                $emails = [];
                for ($i = 1; $i <= 5; $i++) {
                    $e = $get("Email $i");
                    if ($e !== '') $emails[] = $e;
                }

                $socials = [];
                foreach ($socialPlatforms as $p) {
                    $u = $get($p);
                    if ($u !== '') $socials[] = $u;
                }
                $other = $get('Other Socials');
                if ($other !== '') {
                    foreach (preg_split('/[;,]\s*/', $other) as $u) {
                        $u = trim($u);
                        if ($u !== '') $socials[] = $u;
                    }
                }

                $status = strtolower($get('Status'));
                if (!in_array($status, ['cold','warm','hot'])) $status = 'cold';

                $pipeline = strtolower($get('Pipeline Stage'));
                if (!in_array($pipeline, ['new','contacted','engaged','client','no_response'])) $pipeline = 'new';

                $phone = $get('Phone');
                $website = $get('Website');
                $notes = $get('Notes');
                $rating = $get('Rating');
                $reviews = $get('Reviews');

                try {
                    $stmt->execute([
                        $listId, $userId,
                        $businessName,
                        $address,
                        $get('City'),
                        $get('State'),
                        $phone,
                        $website,
                        $rating !== '' ? floatval($rating) : null,
                        $reviews !== '' ? intval($reviews) : 0,
                        $status,
                        $pipeline,
                        $notes,
                        json_encode($emails),
                        json_encode($socials),
                        !empty($emails) ? 1 : 0,
                        !empty($socials) ? 1 : 0,
                        $phone !== '' ? 1 : 0,
                        $website !== '' ? 1 : 0,
                        $notes !== '' ? 1 : 0
                    ]);
                    $inserted++;
                } catch (Exception $e) {
                    $errors++;
                }
            }
            fclose($fh);

            $pdo->prepare("UPDATE lead_lists SET updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$listId, $userId]);
            echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors]);
            exit;

        case 'importCSVBatch':
            $listId = intval($input['list_id'] ?? 0);
            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            $own = $pdo->prepare("SELECT id FROM lead_lists WHERE id = ? AND user_id = ?");
            $own->execute([$listId, $userId]);
            if (!$own->fetchColumn()) { echo json_encode(['success' => false, 'error' => 'List not found']); exit; }

            $headers = $input['headers'] ?? [];
            $rows = $input['rows'] ?? [];
            if (!is_array($headers) || empty($headers) || !is_array($rows)) {
                echo json_encode(['success' => false, 'error' => 'Invalid CSV batch']); exit;
            }

            $result = importLeadCsvRows($pdo, $userId, $listId, $headers, $rows);
            $pdo->prepare("UPDATE lead_lists SET updated_at = NOW() WHERE id = ? AND user_id = ?")->execute([$listId, $userId]);
            echo json_encode(['success' => true] + $result);
            exit;

        case 'fireAllScrapes':
            $listId = $input['list_id'] ?? 0;
            // Capped low on purpose: stays under Replicate's burst-of-50 limit AND under the
            // ~60-connection ceiling where firing 100 at once returned http=0 (dropped connections).
            $batchSize = min(25, max(1, intval($input['batch_size'] ?? 25)));

            // Writes a plain-text log you can download over FTP at:  <your app>/enrichment_debug.log
            // Delete the file (or this block) once you're done debugging.
            $debugLog = __DIR__ . '/enrichment_debug.log';
            $dbg = function($msg) use ($debugLog) {
                @file_put_contents($debugLog, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
            };
            $dbg("================= fireAllScrapes run =================");
            $dbg("list_id=" . var_export($listId, true) . " user_id=$userId batch=$batchSize");
            $dbg("REPLICATE_API_KEY " . (defined('REPLICATE_API_KEY') && REPLICATE_API_KEY ? 'present (len=' . strlen(REPLICATE_API_KEY) . ')' : '!!! MISSING/EMPTY !!!'));
            $dbg("APP_URL=" . (defined('APP_URL') ? var_export(APP_URL, true) : 'UNDEFINED') . "  webhook=" . (defined('APP_URL') ? APP_URL . '/webhook_scrape.php' : '?'));
            error_log("fireAllScrapes: called list_id=" . var_export($listId, true) . " user_id=$userId batch=$batchSize");
            if (!$listId) { $dbg("ABORT: no list_id"); echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            // Self-heal: the has_website flag can be stale (0) even when a website URL is
            // present in the `website` column, which silently excludes the lead from every
            // enrichment query below. Re-sync the flag from the actual column first.
            $fixFlag = $pdo->prepare("UPDATE lead_list_items SET has_website = 1
                                      WHERE list_id = ? AND user_id = ?
                                        AND has_website = 0
                                        AND website IS NOT NULL AND website != ''");
            $fixFlag->execute([$listId, $userId]);
            $dbg("re-synced has_website on " . $fixFlag->rowCount() . " lead(s) that had a URL but the flag was 0");
            error_log("fireAllScrapes: re-synced has_website on " . $fixFlag->rowCount() . " lead(s)");

            $pdo->prepare("
                UPDATE lead_list_items
                SET enrichment_status = 'pending'
                WHERE list_id = ? AND user_id = ?
                  AND has_website = 1
                  AND (enrichment_status IS NULL OR enrichment_status = 'failed')
                  AND enriched_at IS NULL
            ")->execute([$listId, $userId]);

            $stmt = $pdo->prepare("
                SELECT id, website FROM lead_list_items
                WHERE list_id = ? AND user_id = ?
                  AND enrichment_status = 'pending'
                ORDER BY created_at DESC
                LIMIT $batchSize
            ");
            $stmt->execute([$listId, $userId]);
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $dbg("found " . count($leads) . " pending lead(s) to fire (this many predictions get sent to Replicate this pass)");
            error_log("fireAllScrapes: found " . count($leads) . " pending lead(s) to fire for list_id=$listId");

            $countStmt = $pdo->prepare("
                SELECT
                    COUNT(CASE WHEN enrichment_status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN enrichment_status = 'processing' THEN 1 END) as processing,
                    COUNT(CASE WHEN enrichment_status = 'completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN enrichment_status = 'failed' THEN 1 END) as failed,
                    SUM(has_website) as total_with_website
                FROM lead_list_items WHERE list_id = ? AND user_id = ?
            ");
            $countStmt->execute([$listId, $userId]);
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
            $dbg("status breakdown -> pending={$counts['pending']} processing={$counts['processing']} completed={$counts['completed']} failed={$counts['failed']} total_with_website={$counts['total_with_website']}");

            if (empty($leads)) {
                $dbg("NOTHING FIRED. No leads matched (has_website=1 AND status NULL/failed AND enriched_at NULL). If total_with_website is high but completed is high too, click Re-Enrich All to reset them.");
                echo json_encode([
                    'success' => true, 'fired' => 0,
                    'pending' => (int)$counts['pending'], 'processing' => (int)$counts['processing'],
                    'completed' => (int)$counts['completed'], 'failed' => (int)$counts['failed'],
                    'total_with_website' => (int)$counts['total_with_website']
                ]);
                exit;
            }

            $ids = array_column($leads, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'processing', enriched_at = NULL WHERE id IN ($placeholders)")->execute($ids);

            $replicateKey = REPLICATE_API_KEY;
            $modelVersion = '033c91f07cbb8ff02be2c3e5c4293607466e2ace300a32dfe364d16b2efdbe2c';
            $webhookBase = APP_URL . '/webhook_scrape.php';

            $mh = curl_multi_init();
            $handles = [];
            $outOfCredits = false;
            $resetToPending = $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'pending', replicate_id = NULL WHERE id = ?");

            foreach ($leads as $i => $lead) {
                // Enrichment is FREE — no credit charge. Fire for every lead.
                $url = $lead['website'];
                if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;
                $webhookUrl = $webhookBase . '?lead_id=' . $lead['id'] . '&list_id=' . $listId;

                $ch = curl_init('https://api.replicate.com/v1/predictions');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $replicateKey,
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS => json_encode([
                        'version' => $modelVersion,
                        'input' => [
                            'url' => $url,
                            'max_pages' => 6,
                            'phone_region' => 'US',
                            'timeout_seconds' => 15
                        ],
                        'webhook' => $webhookUrl,
                        'webhook_events_filter' => ['completed']
                    ]),
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 30
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = ['ch' => $ch, 'lead_id' => $lead['id']];
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            $dbg("sending " . count($leads) . " prediction request(s) to https://api.replicate.com/v1/predictions ...");

            $saveRepId = $pdo->prepare("UPDATE lead_list_items SET replicate_id = ? WHERE id = ?");
            $markFailed = $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'failed', enriched_at = NOW() WHERE id = ?");
            $resetPending = $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'pending', replicate_id = NULL WHERE id = ?");
            $actualFired = 0;
            $failCount = 0;
            $retryCount = 0;
            $maxRetryAfter = 0;
            foreach ($handles as $h) {
                $body = curl_multi_getcontent($h['ch']);
                $httpCode = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
                $curlErr = curl_error($h['ch']);
                $resp = json_decode($body, true);
                if (!empty($resp['id'])) {
                    $saveRepId->execute([$resp['id'], $h['lead_id']]);
                    $actualFired++;
                    $dbg("  lead {$h['lead_id']}: OK  http=$httpCode  prediction={$resp['id']}");
                } elseif ($httpCode == 429 || $httpCode == 0 || $httpCode >= 500) {
                    // Transient: rate-limit (429), dropped/no-response connection (0), or server error (5xx).
                    // No prediction created — leave the lead PENDING to retry. (Enrichment is free.)
                    $resetPending->execute([$h['lead_id']]);
                    $retryCount++;
                    if (isset($resp['retry_after'])) $maxRetryAfter = max($maxRetryAfter, (int)$resp['retry_after']);
                    $dbg("  lead {$h['lead_id']}: RETRY (left pending) http=$httpCode" . (isset($resp['retry_after']) ? " retry_after={$resp['retry_after']}s" : ""));
                } else {
                    // Genuine, non-retryable error (401 bad key, 422 bad input/version, 404, etc.).
                    // No prediction created -> mark the lead failed. (Enrichment is free.)
                    $failCount++;
                    $errDetail = $curlErr ? "cURL: $curlErr" : "HTTP $httpCode - " . substr($body, 0, 500);
                    error_log("Replicate API error for lead {$h['lead_id']}: $errDetail");
                    $dbg("  lead {$h['lead_id']}: FAIL (credit refunded) http=$httpCode body=" . substr($body, 0, 300));
                    $markFailed->execute([$h['lead_id']]);
                }
                curl_multi_remove_handle($mh, $h['ch']);
                curl_close($h['ch']);
            }
            curl_multi_close($mh);
            $dbg("DONE this pass: fired=$actualFired retry_pending=$retryCount failed=$failCount"
                . ($maxRetryAfter > 0 ? "  (throttled; leads left pending will retry next pass; Replicate suggested ~{$maxRetryAfter}s)" : "")
                . ($failCount > 0 && $actualFired === 0 && $retryCount === 0 ? "  <-- ALL hard-failed; check http/body above (401=bad key, 422=bad input/version)" : ""));

            if ($outOfCredits) $dbg("OUT OF CREDITS: stopped firing; remaining leads left pending until the user tops up.");

            $countStmt->execute([$listId, $userId]);
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true, 'fired' => $actualFired,
                'out_of_credits' => $outOfCredits,
                'credits' => currentCredits($pdo, $userId),
                'pending' => (int)$counts['pending'], 'processing' => (int)$counts['processing'],
                'completed' => (int)$counts['completed'], 'failed' => (int)$counts['failed'],
                'total_with_website' => (int)$counts['total_with_website']
            ]);
            exit;

        case 'getEnrichmentProgress':
            $listId = $_GET['list_id'] ?? 0;
            if (!$listId) { echo json_encode(['success' => false]); exit; }

            $stmt = $pdo->prepare("
                SELECT
                    SUM(has_website) as total_with_website,
                    COUNT(CASE WHEN enrichment_status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN enrichment_status = 'processing' THEN 1 END) as processing,
                    COUNT(CASE WHEN enrichment_status = 'completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN enrichment_status = 'failed' THEN 1 END) as failed,
                    COUNT(CASE WHEN enriched_at IS NOT NULL THEN 1 END) as enriched,
                    SUM(has_email) as with_emails,
                    SUM(has_socials) as with_socials,
                    COUNT(CASE WHEN has_website = 1 AND (enrichment_status IS NULL OR enrichment_status = 'failed') AND enriched_at IS NULL THEN 1 END) as needs_enrichment
                FROM lead_list_items WHERE list_id = ? AND user_id = ?
            ");
            $stmt->execute([$listId, $userId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'total' => (int)$stats['total_with_website'],
                'enriched' => (int)$stats['enriched'],
                'pending' => (int)$stats['pending'],
                'processing' => (int)$stats['processing'],
                'completed' => (int)$stats['completed'],
                'failed' => (int)$stats['failed'],
                'needs_enrichment' => (int)$stats['needs_enrichment'],
                'with_emails' => (int)$stats['with_emails'],
                'with_socials' => (int)$stats['with_socials']
            ]);
            exit;

        case 'recoverStuckEnrichments':
            $listId = $input['list_id'] ?? 0;
            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            $pdo->prepare("
                UPDATE lead_list_items
                SET enrichment_status = 'failed', enriched_at = NOW()
                WHERE list_id = ? AND user_id = ?
                  AND enrichment_status = 'processing'
                  AND enriched_at IS NULL
                  AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            ")->execute([$listId, $userId]);

            $stuckStmt = $pdo->prepare("
                SELECT id, replicate_id, list_id FROM lead_list_items
                WHERE list_id = ? AND user_id = ?
                  AND enrichment_status = 'processing'
                  AND replicate_id IS NOT NULL AND replicate_id != ''
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stuckStmt->execute([$listId, $userId]);
            $stuck = $stuckStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($stuck)) {
                $pdo->prepare("
                    UPDATE lead_list_items
                    SET enrichment_status = 'failed', enriched_at = NOW()
                    WHERE list_id = ? AND user_id = ?
                      AND enrichment_status = 'processing'
                      AND replicate_id IS NULL
                ")->execute([$listId, $userId]);
                echo json_encode(['success' => true, 'recovered' => 0, 'failed' => 0]);
                exit;
            }

            $replicateKey = REPLICATE_API_KEY;
            $recovered = 0;
            $failedCount = 0;
            $stillProcessing = 0;

            $mh = curl_multi_init();
            $pollHandles = [];
            foreach ($stuck as $s) {
                $ch = curl_init('https://api.replicate.com/v1/predictions/' . $s['replicate_id']);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $replicateKey],
                    CURLOPT_TIMEOUT => 10
                ]);
                curl_multi_add_handle($mh, $ch);
                $pollHandles[] = ['ch' => $ch, 'lead_id' => $s['id'], 'list_id' => $s['list_id']];
            }

            $running = null;
            do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

            $webhookBase = APP_URL . '/webhook_scrape.php';
            foreach ($pollHandles as $ph) {
                $body = curl_multi_getcontent($ph['ch']);
                $resp = json_decode($body, true);
                curl_multi_remove_handle($mh, $ph['ch']);
                curl_close($ph['ch']);

                if (!$resp || !isset($resp['status'])) {
                    $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'failed', enriched_at = NOW() WHERE id = ?")->execute([$ph['lead_id']]);
                    $failedCount++;
                    continue;
                }

                if ($resp['status'] === 'succeeded') {
                    $output = $resp['output'] ?? [];
                    $fakeWebhookData = json_encode(['id' => $resp['id'], 'status' => 'succeeded', 'output' => $output]);
                    $ch2 = curl_init($webhookBase . '?lead_id=' . $ph['lead_id'] . '&list_id=' . $ph['list_id']);
                    curl_setopt_array($ch2, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                        CURLOPT_POSTFIELDS => $fakeWebhookData,
                        CURLOPT_TIMEOUT => 5
                    ]);
                    curl_exec($ch2);
                    curl_close($ch2);
                    $recovered++;
                } elseif (in_array($resp['status'], ['failed', 'canceled'])) {
                    $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'failed', enriched_at = NOW() WHERE id = ?")->execute([$ph['lead_id']]);
                    $failedCount++;
                } elseif (in_array($resp['status'], ['starting', 'processing'])) {
                    $stillProcessing++;
                } else {
                    $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'failed', enriched_at = NOW() WHERE id = ?")->execute([$ph['lead_id']]);
                    $failedCount++;
                }
            }
            curl_multi_close($mh);

            echo json_encode(['success' => true, 'recovered' => $recovered, 'failed' => $failedCount, 'still_processing' => $stillProcessing, 'checked' => count($stuck)]);
            exit;

        case 'retryFailedEnrichments':
            $listId = $input['list_id'] ?? 0;
            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            $failedStmt = $pdo->prepare("
                SELECT id, replicate_id, website, list_id FROM lead_list_items
                WHERE list_id = ? AND user_id = ?
                  AND enrichment_status = 'failed'
                  AND has_website = 1
                ORDER BY updated_at DESC
                LIMIT 10
            ");
            $failedStmt->execute([$listId, $userId]);
            $failedItems = $failedStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($failedItems)) {
                echo json_encode(['success' => true, 'recovered' => 0, 'retried' => 0]);
                exit;
            }

            $replicateKey = REPLICATE_API_KEY;
            $webhookBase = APP_URL . '/webhook_scrape.php';
            $modelVersion = '033c91f07cbb8ff02be2c3e5c4293607466e2ace300a32dfe364d16b2efdbe2c';
            $recovered = 0;
            $retried = 0;

            $withId = [];
            $withoutId = [];
            foreach ($failedItems as $item) {
                if (!empty($item['replicate_id'])) {
                    $withId[] = $item;
                } else {
                    $withoutId[] = $item;
                }
            }

            if (!empty($withId)) {
                $mh = curl_multi_init();
                $pollHandles = [];
                foreach ($withId as $item) {
                    $ch = curl_init('https://api.replicate.com/v1/predictions/' . $item['replicate_id']);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $replicateKey],
                        CURLOPT_TIMEOUT => 10
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $pollHandles[] = ['ch' => $ch, 'item' => $item];
                }
                $running = null;
                do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

                foreach ($pollHandles as $ph) {
                    $body = curl_multi_getcontent($ph['ch']);
                    $resp = json_decode($body, true);
                    curl_multi_remove_handle($mh, $ph['ch']);
                    curl_close($ph['ch']);

                    if ($resp && $resp['status'] === 'succeeded') {
                        $output = $resp['output'] ?? [];
                        $fakeData = json_encode(['id' => $resp['id'], 'status' => 'succeeded', 'output' => $output]);
                        $ch2 = curl_init($webhookBase . '?lead_id=' . $ph['item']['id'] . '&list_id=' . $ph['item']['list_id']);
                        curl_setopt_array($ch2, [
                            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                            CURLOPT_POSTFIELDS => $fakeData, CURLOPT_TIMEOUT => 5
                        ]);
                        curl_exec($ch2);
                        curl_close($ch2);
                        $recovered++;
                    } elseif ($resp && in_array($resp['status'], ['processing', 'starting'])) {
                        $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'processing' WHERE id = ?")->execute([$ph['item']['id']]);
                        $recovered++;
                    } else {
                        $withoutId[] = $ph['item'];
                    }
                }
                curl_multi_close($mh);
            }

            if (!empty($withoutId)) {
                $idsToRetry = array_column($withoutId, 'id');
                $placeholders = implode(',', array_fill(0, count($idsToRetry), '?'));
                $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'processing', enriched_at = NULL, replicate_id = NULL WHERE id IN ($placeholders)")->execute($idsToRetry);

                $mh = curl_multi_init();
                $handles = [];
                foreach ($withoutId as $item) {
                    // Enrichment is FREE — no credit charge.
                    $url = $item['website'];
                    if (!preg_match('/^https?:\/\//', $url)) $url = 'https://' . $url;
                    $webhookUrl = $webhookBase . '?lead_id=' . $item['id'] . '&list_id=' . $listId;
                    $ch = curl_init('https://api.replicate.com/v1/predictions');
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $replicateKey, 'Content-Type: application/json'],
                        CURLOPT_POSTFIELDS => json_encode([
                            'version' => $modelVersion,
                            'input' => [
                                'url' => $url,
                                'max_pages' => 6,
                                'phone_region' => 'US',
                                'timeout_seconds' => 15
                            ],
                            'webhook' => $webhookUrl,
                            'webhook_events_filter' => ['completed']
                        ]),
                        CURLOPT_TIMEOUT => 10
                    ]);
                    curl_multi_add_handle($mh, $ch);
                    $handles[] = ['ch' => $ch, 'lead_id' => $item['id']];
                }
                $running = null;
                do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

                $saveRepId = $pdo->prepare("UPDATE lead_list_items SET replicate_id = ? WHERE id = ?");
                foreach ($handles as $h) {
                    $body = curl_multi_getcontent($h['ch']);
                    $resp = json_decode($body, true);
                    if (!empty($resp['id'])) {
                        $saveRepId->execute([$resp['id'], $h['lead_id']]);
                        $retried++;
                    } else {
                        $pdo->prepare("UPDATE lead_list_items SET enrichment_status = 'failed', enriched_at = NOW() WHERE id = ?")->execute([$h['lead_id']]);
                    }
                    curl_multi_remove_handle($mh, $h['ch']);
                    curl_close($h['ch']);
                }
                curl_multi_close($mh);
            }

            echo json_encode(['success' => true, 'recovered' => $recovered, 'retried' => $retried, 'checked' => count($failedItems)]);
            exit;

        case 'forceReenrich':
        case 'adminForceReenrich':
            $listId = $input['list_id'] ?? 0;
            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            // Reset every lead that actually has a website URL — keying off the `website`
            // column, not the has_website flag which may be stale — and re-sync the flag.
            $pdo->prepare("
                UPDATE lead_list_items
                SET enrichment_status = NULL, enriched_at = NULL, replicate_id = NULL, has_website = 1
                WHERE list_id = ? AND user_id = ?
                  AND website IS NOT NULL AND website != ''
            ")->execute([$listId, $userId]);

            $totalStmt = $pdo->prepare("
                SELECT COUNT(*) FROM lead_list_items
                WHERE list_id = ? AND user_id = ?
                  AND website IS NOT NULL AND website != ''
            ");
            $totalStmt->execute([$listId, $userId]);
            $totalWebsites = (int)$totalStmt->fetchColumn();

            echo json_encode(['success' => true, 'reset' => $totalWebsites]);
            exit;

        case 'saveGHLConnection':
            $name = trim($input['name'] ?? '');
            $apiKey = trim($input['api_key'] ?? '');
            $locationId = trim($input['location_id'] ?? '');
            $connId = $input['connection_id'] ?? null;
            if (!$name || !$locationId) { echo json_encode(['success' => false, 'error' => 'Name and Location ID are required']); exit; }
            if ($connId) {
                if ($apiKey && $apiKey !== 'KEEP_EXISTING') {
                    $pdo->prepare("UPDATE ghl_connections SET name = ?, api_key = ?, location_id = ? WHERE id = ? AND user_id = ?")->execute([$name, $apiKey, $locationId, $connId, $userId]);
                } else {
                    $pdo->prepare("UPDATE ghl_connections SET name = ?, location_id = ? WHERE id = ? AND user_id = ?")->execute([$name, $locationId, $connId, $userId]);
                }
            } else {
                if (!$apiKey) { echo json_encode(['success' => false, 'error' => 'API key is required for new connections']); exit; }
                $pdo->prepare("INSERT INTO ghl_connections (user_id, name, api_key, location_id) VALUES (?,?,?,?)")->execute([$userId, $name, $apiKey, $locationId]);
                $connId = $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'connection_id' => (int)$connId]);
            exit;

        case 'getGHLConnections':
            $s = $pdo->prepare("SELECT id, name, location_id, created_at FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC");
            $s->execute([$userId]);
            echo json_encode(['success' => true, 'connections' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            exit;

        case 'deleteGHLConnection':
            $connId = $input['connection_id'] ?? 0;
            if (!$connId) { echo json_encode(['success' => false, 'error' => 'No connection_id']); exit; }
            $pdo->prepare("DELETE FROM ghl_connections WHERE id = ? AND user_id = ?")->execute([$connId, $userId]);
            echo json_encode(['success' => true]);
            exit;

        case 'ghlProxy':
            $method = strtoupper($input['method'] ?? 'GET');
            $endpoint = $input['endpoint'] ?? '';
            $body = $input['body'] ?? null;
            $connId = $input['connection_id'] ?? 0;
            if (!$endpoint) { echo json_encode(['success' => false, 'error' => 'No endpoint']); exit; }

            if ($connId) {
                $creds = $pdo->prepare("SELECT api_key, location_id FROM ghl_connections WHERE id = ? AND user_id = ?");
                $creds->execute([$connId, $userId]);
            } else {
                $creds = $pdo->prepare("SELECT api_key, location_id FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
                $creds->execute([$userId]);
            }
            $gc = $creds->fetch(PDO::FETCH_ASSOC);
            if (empty($gc['api_key'])) { echo json_encode(['success' => false, 'error' => 'Free CRM not connected']); exit; }

            $url = 'https://services.leadconnectorhq.com' . $endpoint;
            $ch = curl_init($url);
            $headers = [
                'Authorization: Bearer ' . $gc['api_key'],
                'Version: 2021-07-28',
                'Content-Type: application/json'
            ];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 15
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $parsed = json_decode($response, true);
            echo json_encode(['success' => $httpCode >= 200 && $httpCode < 300, 'status' => $httpCode, 'data' => $parsed]);
            exit;

        case 'ghlCreateImportFromList':
            $listId = $input['list_id'] ?? 0;
            $tags = $input['tags'] ?? [];
            $workflowId = $input['workflow_id'] ?? '';
            $workflowName = $input['workflow_name'] ?? '';
            $drip = $input['drip'] ?? null;
            $connId = $input['connection_id'] ?? 0;
            $filters = $input['filters'] ?? [];

            if (!$listId) { echo json_encode(['success' => false, 'error' => 'No list_id']); exit; }

            if ($connId) { $creds = $pdo->prepare("SELECT id, name, api_key, location_id FROM ghl_connections WHERE id = ? AND user_id = ?"); $creds->execute([$connId, $userId]); }
            else { $creds = $pdo->prepare("SELECT id, name, api_key, location_id FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC LIMIT 1"); $creds->execute([$userId]); }
            $gc = $creds->fetch(PDO::FETCH_ASSOC);
            if (empty($gc['api_key']) || empty($gc['location_id'])) { echo json_encode(['success' => false, 'error' => 'Free CRM not connected']); exit; }
            $connId = $gc['id'];
            $connName = $gc['name'];

            $fWhere = "list_id = ? AND user_id = ?";
            $fParams = [$listId, $userId];
            $fHas = $filters['has'] ?? '';
            $fStatus = $filters['status'] ?? '';
            $fPipeline = $filters['pipeline'] ?? '';
            $fSearch = $filters['search'] ?? '';
            $fImport = $filters['import_filter'] ?? '';
            if ($fStatus && in_array($fStatus, ['cold','warm','hot'])) { $fWhere .= " AND status = ?"; $fParams[] = $fStatus; }
            if ($fHas === 'has_email') { $fWhere .= " AND has_email = 1"; }
            elseif ($fHas === 'has_phone') { $fWhere .= " AND has_phone = 1"; }
            elseif ($fHas === 'has_both') { $fWhere .= " AND has_email = 1 AND has_phone = 1"; }
            if ($fPipeline && in_array($fPipeline, ['new','contacted','engaged','client','no_response'])) {
                if ($fPipeline === 'new') { $fWhere .= " AND (pipeline_stage = 'new' OR pipeline_stage IS NULL)"; }
                else { $fWhere .= " AND pipeline_stage = ?"; $fParams[] = $fPipeline; }
            }
            if ($fSearch) { $fWhere .= " AND (business_name LIKE ? OR address LIKE ?)"; $fParams[] = "%$fSearch%"; $fParams[] = "%$fSearch%"; }
            if ($fImport === 'not_imported') { $fWhere .= " AND (ghl_contact_id IS NULL OR ghl_contact_id = '')"; }
            elseif ($fImport === 'previously_imported') { $fWhere .= " AND ghl_contact_id IS NOT NULL AND ghl_contact_id != ''"; }

            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM lead_list_items WHERE $fWhere");
            $countStmt->execute($fParams);
            $totalLeads = (int)$countStmt->fetchColumn();
            if ($totalLeads === 0) { echo json_encode(['success' => false, 'error' => 'No leads match filters']); exit; }

            $dripEnabled = !empty($drip['enabled']);
            $dripBatch = $dripEnabled ? max(1, intval($drip['batch_size'] ?? 50)) : $totalLeads;
            $dripInterval = $dripEnabled ? max(1, intval($drip['interval_minutes'] ?? 60)) : 0;
            $dripTz = $drip['timezone'] ?? 'America/New_York';
            $dripHour = isset($drip['send_hour']) ? intval($drip['send_hour']) : null;
            $dripMinute = isset($drip['send_minute']) ? intval($drip['send_minute']) : null;
            $nextBatch = null;
            if ($dripEnabled && $dripHour !== null) {
                $tz = new DateTimeZone($dripTz);
                $now = new DateTime('now', $tz);
                $send = (clone $now)->setTime($dripHour, $dripMinute ?? 0);
                if ($send <= $now) $send->modify('+1 day');
                $send->setTimezone(new DateTimeZone('UTC'));
                $nextBatch = $send->format('Y-m-d H:i:s');
            }

            $pdo->prepare("INSERT INTO ghl_import_logs (user_id, list_id, connection_id, connection_name, status, total_contacts, tags, workflow_id, workflow_name, drip_enabled, drip_batch_size, drip_interval_minutes, drip_timezone, drip_send_hour, drip_send_minute, drip_next_batch_at, errors) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $userId, $listId, $connId, $connName,
                $dripEnabled && $dripHour !== null ? 'pending' : 'running',
                $totalLeads, json_encode($tags), $workflowId, $workflowName,
                $dripEnabled ? 1 : 0, $dripBatch, $dripInterval, $dripTz,
                $dripHour, $dripMinute, $nextBatch, '[]'
            ]);
            $importId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO ghl_import_items (import_log_id, lead_id, user_id, status, lead_data) VALUES (?,?,?,?,?)");
            $leadStmt = $pdo->prepare("SELECT id, business_name, address, city, state, phone, website, emails, social_media_links, ghl_contact_id FROM lead_list_items WHERE $fWhere ORDER BY created_at DESC");
            $leadStmt->execute($fParams);
            while ($r = $leadStmt->fetch(PDO::FETCH_ASSOC)) {
                $emails = json_decode($r['emails'] ?: '[]', true);
                $leadData = json_encode([
                    '_leadId' => (int)$r['id'], 'firstName' => '', 'lastName' => '',
                    'companyName' => $r['business_name'], 'email' => $emails[0] ?? '',
                    'phone' => $r['phone'], 'city' => $r['city'], 'state' => $r['state'],
                    'website' => $r['website'], 'address' => $r['address']
                ]);
                $itemStmt->execute([$importId, $r['id'], $userId, 'pending', $leadData]);
            }

            echo json_encode(['success' => true, 'import_id' => (int)$importId, 'total' => $totalLeads, 'mode' => ($dripEnabled && $dripHour !== null) ? 'drip' : 'immediate', 'next_batch_at' => $nextBatch]);
            exit;

        case 'ghlCreateImport':
            $leads = $input['leads'] ?? [];
            $tags = $input['tags'] ?? [];
            $workflowId = $input['workflow_id'] ?? '';
            $workflowName = $input['workflow_name'] ?? '';
            $listId = $input['list_id'] ?? 0;
            $drip = $input['drip'] ?? null;
            $connId = $input['connection_id'] ?? 0;
            if (empty($leads)) { echo json_encode(['success' => false, 'error' => 'No leads']); exit; }

            if ($connId) {
                $creds = $pdo->prepare("SELECT id, name, api_key, location_id FROM ghl_connections WHERE id = ? AND user_id = ?");
                $creds->execute([$connId, $userId]);
            } else {
                $creds = $pdo->prepare("SELECT id, name, api_key, location_id FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
                $creds->execute([$userId]);
            }
            $gc = $creds->fetch(PDO::FETCH_ASSOC);
            if (empty($gc['api_key']) || empty($gc['location_id'])) {
                echo json_encode(['success' => false, 'error' => 'Free CRM not connected']);
                exit;
            }
            $connId = $gc['id'];
            $connName = $gc['name'];

            $dripEnabled = !empty($drip['enabled']);
            $dripBatch = $dripEnabled ? max(1, intval($drip['batch_size'] ?? 50)) : count($leads);
            $dripInterval = $dripEnabled ? max(1, intval($drip['interval_minutes'] ?? 60)) : 0;
            $dripTz = $drip['timezone'] ?? 'America/New_York';
            $dripHour = isset($drip['send_hour']) ? intval($drip['send_hour']) : null;
            $dripMinute = isset($drip['send_minute']) ? intval($drip['send_minute']) : null;

            $nextBatch = null;
            if ($dripEnabled && $dripHour !== null) {
                $tz = new DateTimeZone($dripTz);
                $now = new DateTime('now', $tz);
                $send = (clone $now)->setTime($dripHour, $dripMinute ?? 0);
                if ($send <= $now) $send->modify('+1 day');
                $send->setTimezone(new DateTimeZone('UTC'));
                $nextBatch = $send->format('Y-m-d H:i:s');
            }

            $pdo->prepare("INSERT INTO ghl_import_logs (user_id, list_id, connection_id, connection_name, status, total_contacts, tags, workflow_id, workflow_name, drip_enabled, drip_batch_size, drip_interval_minutes, drip_timezone, drip_send_hour, drip_send_minute, drip_next_batch_at, errors) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $userId, $listId, $connId, $connName,
                $dripEnabled && $dripHour !== null ? 'pending' : 'running',
                count($leads),
                json_encode($tags), $workflowId, $workflowName,
                $dripEnabled ? 1 : 0, $dripBatch, $dripInterval, $dripTz,
                $dripHour, $dripMinute, $nextBatch, '[]'
            ]);
            $importId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO ghl_import_items (import_log_id, lead_id, user_id, status, lead_data) VALUES (?,?,?,?,?)");
            foreach ($leads as $lead) {
                $itemStmt->execute([$importId, $lead['_leadId'] ?? 0, $userId, 'pending', json_encode($lead)]);
            }

            if (!$dripEnabled || $dripHour === null) {
                echo json_encode(['success' => true, 'import_id' => (int)$importId, 'mode' => 'immediate']);
            } else {
                echo json_encode(['success' => true, 'import_id' => (int)$importId, 'mode' => 'drip', 'next_batch_at' => $nextBatch]);
            }
            exit;

        case 'ghlProcessBatch':
            $importId = $input['import_id'] ?? 0;
            if (!$importId) { echo json_encode(['success' => false, 'error' => 'No import_id']); exit; }

            $log = $pdo->prepare("SELECT * FROM ghl_import_logs WHERE id = ? AND user_id = ?");
            $log->execute([$importId, $userId]);
            $importLog = $log->fetch(PDO::FETCH_ASSOC);
            if (!$importLog) { echo json_encode(['success' => false, 'error' => 'Import not found']); exit; }
            if (in_array($importLog['status'], ['cancelled','completed'])) {
                echo json_encode(['success' => true, 'done' => true, 'status' => $importLog['status']]);
                exit;
            }

            if ($importLog['drip_enabled'] && !empty($importLog['drip_next_batch_at'])) {
                $nowUTC = new DateTime('now', new DateTimeZone('UTC'));
                $nextAt = new DateTime($importLog['drip_next_batch_at'], new DateTimeZone('UTC'));
                if ($nextAt > $nowUTC) {
                    $remainStmt2 = $pdo->prepare("SELECT COUNT(*) FROM ghl_import_items WHERE import_log_id = ? AND status = 'pending'");
                    $remainStmt2->execute([$importId]);
                    $remaining2 = (int)$remainStmt2->fetchColumn();
                    $totals2 = $pdo->prepare("SELECT imported, updated, failed, processed, total_contacts, status FROM ghl_import_logs WHERE id = ?");
                    $totals2->execute([$importId]);
                    echo json_encode([
                        'success' => true,
                        'waiting' => true,
                        'drip_next_batch_at' => $importLog['drip_next_batch_at'],
                        'wait_seconds' => $nextAt->getTimestamp() - $nowUTC->getTimestamp(),
                        'remaining' => $remaining2,
                        'totals' => $totals2->fetch(PDO::FETCH_ASSOC),
                        'done' => false
                    ]);
                    exit;
                }
            }

            $connIdForImport = $importLog['connection_id'] ?? null;
            if ($connIdForImport) {
                $creds = $pdo->prepare("SELECT api_key, location_id FROM ghl_connections WHERE id = ? AND user_id = ?");
                $creds->execute([$connIdForImport, $userId]);
            } else {
                $creds = $pdo->prepare("SELECT api_key, location_id FROM ghl_connections WHERE user_id = ? ORDER BY created_at ASC LIMIT 1");
                $creds->execute([$userId]);
            }
            $gc = $creds->fetch(PDO::FETCH_ASSOC);
            if (empty($gc['api_key']) || empty($gc['location_id'])) {
                echo json_encode(['success' => false, 'error' => 'Free CRM not connected']);
                exit;
            }

            $apiKey = $gc['api_key'];
            $locationId = $gc['location_id'];
            $tags = json_decode($importLog['tags'] ?: '[]', true);
            $workflowId = $importLog['workflow_id'];
            $batchSize = $importLog['drip_enabled'] ? $importLog['drip_batch_size'] : 10;

            $pdo->prepare("UPDATE ghl_import_logs SET status = 'running', drip_next_batch_at = NULL WHERE id = ?")->execute([$importId]);

            $pendingStmt = $pdo->prepare("SELECT * FROM ghl_import_items WHERE import_log_id = ? AND status = 'pending' ORDER BY id ASC LIMIT ?");
            $pendingStmt->bindValue(1, (int)$importId, PDO::PARAM_INT);
            $pendingStmt->bindValue(2, $batchSize, PDO::PARAM_INT);
            $pendingStmt->execute();
            $items = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

            $results = ['imported' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'processed' => 0];
            $markStmt = $pdo->prepare("UPDATE lead_list_items SET outreach_email = 1, ghl_contact_id = ?, pipeline_stage = CASE WHEN pipeline_stage = 'new' THEN 'contacted' ELSE pipeline_stage END, first_contacted_at = COALESCE(first_contacted_at, NOW()) WHERE id = ? AND user_id = ?");
            $updateItem = $pdo->prepare("UPDATE ghl_import_items SET status = ?, ghl_contact_id = ?, is_new = ?, error_message = ? WHERE id = ?");

            foreach ($items as $item) {
                $lead = json_decode($item['lead_data'], true);
                $contactData = [
                    'locationId' => $locationId,
                    'firstName' => $lead['firstName'] ?? '',
                    'lastName' => $lead['lastName'] ?? '',
                    'companyName' => $lead['companyName'] ?? '',
                    'email' => $lead['email'] ?? '',
                    'phone' => $lead['phone'] ?? '',
                    'website' => $lead['website'] ?? '',
                    'address1' => $lead['address'] ?? '',
                    'city' => $lead['city'] ?? '',
                    'state' => $lead['state'] ?? '',
                    'tags' => !empty($tags) ? $tags : [],
                    'source' => 'Lead Lists CRM'
                ];
                foreach (['firstName','lastName','companyName','email','phone','website','address1','city','state'] as $k) {
                    if (empty($contactData[$k])) unset($contactData[$k]);
                }
                if (empty($contactData['tags'])) unset($contactData['tags']);

                $ch = curl_init('https://services.leadconnectorhq.com/contacts/upsert');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Version: 2021-07-28',
                        'Content-Type: application/json'
                    ],
                    CURLOPT_POSTFIELDS => json_encode($contactData),
                    CURLOPT_TIMEOUT => 10
                ]);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $parsed = json_decode($resp, true);
                $contactId = $parsed['contact']['id'] ?? null;

                if ($code >= 200 && $code < 300 && $contactId) {
                    $isNew = ($parsed['new'] ?? false) === true;
                    if ($isNew) $results['imported']++;
                    else $results['updated']++;

                    $updateItem->execute(['success', $contactId, $isNew ? 1 : 0, null, $item['id']]);

                    if (!empty($tags) && $contactId) {
                        $tch = curl_init("https://services.leadconnectorhq.com/contacts/{$contactId}/tags");
                        curl_setopt_array($tch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_HTTPHEADER => [
                                'Authorization: Bearer ' . $apiKey,
                                'Version: 2021-07-28',
                                'Content-Type: application/json'
                            ],
                            CURLOPT_POSTFIELDS => json_encode(['tags' => array_values($tags)]),
                            CURLOPT_TIMEOUT => 5
                        ]);
                        curl_exec($tch);
                        curl_close($tch);
                    }

                    if ($workflowId && $contactId) {
                        $wch = curl_init("https://services.leadconnectorhq.com/contacts/{$contactId}/workflow/{$workflowId}");
                        curl_setopt_array($wch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_HTTPHEADER => [
                                'Authorization: Bearer ' . $apiKey,
                                'Version: 2021-07-28',
                                'Content-Type: application/json'
                            ],
                            CURLOPT_POSTFIELDS => '{}',
                            CURLOPT_TIMEOUT => 5
                        ]);
                        curl_exec($wch);
                        curl_close($wch);
                    }

                    if ($item['lead_id']) {
                        $markStmt->execute([$contactId, $item['lead_id'], $userId]);
                    }
                } else {
                    $results['failed']++;
                    $errMsg = $parsed['message'] ?? $parsed['error'] ?? "HTTP $code";
                    $results['errors'][] = ($lead['companyName'] ?? 'Unknown') . ': ' . $errMsg;
                    $updateItem->execute(['failed', null, 0, $errMsg, $item['id']]);
                }

                $results['processed']++;
                usleep(200000);
            }

            $existingErrors = $pdo->prepare("SELECT errors FROM ghl_import_logs WHERE id = ?");
            $existingErrors->execute([$importId]);
            $currentErrors = json_decode($existingErrors->fetchColumn() ?: '[]', true) ?: [];
            $mergedErrors = array_merge($currentErrors, $results['errors']);

            $pdo->prepare("UPDATE ghl_import_logs SET imported = imported + ?, updated = updated + ?, failed = failed + ?, processed = processed + ?, errors = ? WHERE id = ?")->execute([
                $results['imported'], $results['updated'], $results['failed'], $results['processed'],
                json_encode($mergedErrors), $importId
            ]);

            $remainStmt = $pdo->prepare("SELECT COUNT(*) FROM ghl_import_items WHERE import_log_id = ? AND status = 'pending'");
            $remainStmt->execute([$importId]);
            $remaining = (int)$remainStmt->fetchColumn();

            if ($remaining === 0) {
                $pdo->prepare("UPDATE ghl_import_logs SET status = 'completed', completed_at = NOW() WHERE id = ?")->execute([$importId]);
            } elseif ($importLog['drip_enabled']) {
                $tz = new DateTimeZone($importLog['drip_timezone'] ?: 'UTC');
                $next = new DateTime('now', $tz);
                $next->modify('+' . $importLog['drip_interval_minutes'] . ' minutes');
                $next->setTimezone(new DateTimeZone('UTC'));
                $pdo->prepare("UPDATE ghl_import_logs SET status = 'pending', drip_next_batch_at = ? WHERE id = ?")->execute([$next->format('Y-m-d H:i:s'), $importId]);
            }

            $totals = $pdo->prepare("SELECT imported, updated, failed, processed, total_contacts, status FROM ghl_import_logs WHERE id = ?");
            $totals->execute([$importId]);
            $totalsRow = $totals->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'batch_results' => $results,
                'totals' => $totalsRow,
                'remaining' => $remaining,
                'done' => $remaining === 0
            ]);
            exit;

        case 'ghlGetImportLogs':
            $listId = $_GET['list_id'] ?? $input['list_id'] ?? 0;
            $where = "user_id = ?";
            $params = [$userId];
            if ($listId) { $where .= " AND list_id = ?"; $params[] = $listId; }
            $stmt = $pdo->prepare("SELECT l.*, ll.name as list_name FROM ghl_import_logs l LEFT JOIN lead_lists ll ON l.list_id = ll.id WHERE l.$where ORDER BY l.created_at DESC LIMIT 50");
            $stmt->execute($params);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($logs as &$lg) {
                $lg['tags'] = json_decode($lg['tags'] ?: '[]', true);
                $errRaw = $lg['errors'] ?: '[]';
                $decoded = json_decode($errRaw, true);
                $flat = [];
                if (is_array($decoded)) { foreach ($decoded as $e) { if (is_array($e)) foreach ($e as $ee) $flat[] = $ee; else $flat[] = $e; } }
                $lg['errors'] = $flat;
            }
            echo json_encode(['success' => true, 'logs' => $logs]);
            exit;

        case 'ghlGetImportStatus':
            $importId = $_GET['import_id'] ?? $input['import_id'] ?? 0;
            $log = $pdo->prepare("SELECT * FROM ghl_import_logs WHERE id = ? AND user_id = ?");
            $log->execute([$importId, $userId]);
            $row = $log->fetch(PDO::FETCH_ASSOC);
            if (!$row) { echo json_encode(['success' => false]); exit; }
            $row['tags'] = json_decode($row['tags'] ?: '[]', true);
            $errRaw = $row['errors'] ?: '[]';
            $decoded = json_decode($errRaw, true);
            $flat = [];
            if (is_array($decoded)) { foreach ($decoded as $e) { if (is_array($e)) foreach ($e as $ee) $flat[] = $ee; else $flat[] = $e; } }
            $row['errors'] = $flat;
            echo json_encode(['success' => true, 'log' => $row]);
            exit;

        case 'ghlPauseImport':
            $importId = $input['import_id'] ?? 0;
            $pdo->prepare("UPDATE ghl_import_logs SET status = 'paused' WHERE id = ? AND user_id = ? AND status IN ('running','pending')")->execute([$importId, $userId]);
            echo json_encode(['success' => true]);
            exit;

        case 'ghlResumeImport':
            $importId = $input['import_id'] ?? 0;
            $pdo->prepare("UPDATE ghl_import_logs SET status = 'running' WHERE id = ? AND user_id = ? AND status = 'paused'")->execute([$importId, $userId]);
            echo json_encode(['success' => true]);
            exit;

        case 'ghlCancelImport':
            $importId = $input['import_id'] ?? 0;
            $pdo->prepare("UPDATE ghl_import_logs SET status = 'cancelled', completed_at = NOW() WHERE id = ? AND user_id = ? AND status IN ('running','pending','paused')")->execute([$importId, $userId]);
            $pdo->prepare("UPDATE ghl_import_items SET status = 'skipped' WHERE import_log_id = ? AND status = 'pending'")->execute([$importId]);
            echo json_encode(['success' => true]);
            exit;

        case 'claimShareCredits':
            try {
                $check = $pdo->prepare("SELECT shared_for_credits FROM users WHERE id = ?");
                $check->execute([$userId]);
                if ($check->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'Already claimed']);
                    exit;
                }
                $pdo->prepare("UPDATE users SET credits = credits + 5, shared_for_credits = 1 WHERE id = ?")->execute([$userId]);
                $cr = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
                $cr->execute([$userId]);
                echo json_encode(['success' => true, 'credits' => (int)$cr->fetchColumn()]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit;

    }
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lead Lists</title>
    <link rel="icon" type="image/jpeg" href="<?php echo APP_LOGO; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-draw@1.0.4/dist/leaflet.draw.js"></script>
    <style>
        :root {
            --bg: #f5f5f7;
            --bg-secondary: #e8e8ed;
            --card: rgba(255,255,255,0.72);
            --card-solid: #ffffff;
            --card-border: rgba(0,0,0,0.06);
            --glass-blur: blur(20px);
            --accent: #c85719;
            --accent-hover: #a84615;
            --accent-light: rgba(200,87,25,0.08);
            --green: #34C759;
            --orange: #ca942a;
            --red: #FF3B30;
            --pink: #FF2D55;
            --purple: #337f83;
            --teal: #1460a6;
            --text: #1d1d1f;
            --text-secondary: #86868b;
            --text-tertiary: #aeaeb2;
            --radius: 16px;
            --radius-sm: 10px;
            --radius-xs: 8px;
            --shadow: 0 2px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.12);
            --transition: 0.2s cubic-bezier(0.25, 0.1, 0.25, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'SF Pro Text', 'Inter', 'Helvetica Neue', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 40px;
        }

        /* Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 34px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text);
        }

        .page-header .subtitle {
            font-size: 15px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        /* Mobile: let the header wrap and give "New List" its own full-width row
           so the button isn't squished next to the title + credits. */
        @media (max-width: 768px) {
            .page-header { flex-wrap: wrap; gap: 14px; align-items: flex-start; }
            .page-header > div:last-child { width: 100%; flex-wrap: wrap; }
            .page-header > div:last-child .btn-primary { flex: 1 1 100%; justify-content: center; }
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-size: 15px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all var(--transition);
            text-decoration: none;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }
        .btn-primary:hover { background: var(--accent-hover); transform: scale(1.02); }

        .btn-secondary {
            background: rgba(0,0,0,0.05);
            color: var(--text);
        }
        .btn-secondary:hover { background: rgba(0,0,0,0.1); }

        .btn-ghost {
            background: transparent;
            color: var(--accent);
            padding: 8px 12px;
        }
        .btn-ghost:hover { background: var(--accent-light); }

        .btn-danger {
            background: var(--red);
            color: white;
        }
        .btn-danger:hover { opacity: 0.9; }

        .btn-sm {
            padding: 6px 14px;
            font-size: 13px;
            border-radius: var(--radius-xs);
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            background: rgba(0,0,0,0.04);
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .btn-icon:hover { background: rgba(0,0,0,0.08); color: var(--text); }

        /* Folder Grid */
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .folder-card {
            background: var(--card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            padding: 24px;
            cursor: pointer;
            transition: all var(--transition);
            position: relative;
            overflow: hidden;
        }
        .folder-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: rgba(200,87,25,0.2);
        }

        .folder-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
        }

        .folder-card .name {
            font-size: 17px;
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text);
        }

        .folder-card .desc {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 16px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .folder-card .meta {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .folder-card .meta span {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .folder-card .score-bar {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .score-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .score-pill.cold { background: rgba(200,87,25,0.1); color: var(--accent); }
        .score-pill.warm { background: rgba(202,148,42,0.1); color: var(--orange); }
        .score-pill.hot { background: rgba(255,59,48,0.1); color: var(--red); }

        .folder-actions {
            position: absolute;
            top: 16px;
            right: 16px;
            display: flex;
            gap: 4px;
            opacity: 0;
            transition: opacity var(--transition);
        }
        .folder-card:hover .folder-actions { opacity: 1; }

        .empty-state {
            text-align: center;
            padding: 80px 40px;
        }

        .empty-state i {
            font-size: 64px;
            color: var(--text-tertiary);
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: var(--text-secondary);
            font-size: 15px;
            margin-bottom: 24px;
        }

        /* Welcome / First-time empty state */
        .welcome-empty {
            max-width: 640px;
            margin: 0 auto;
            padding: 60px 32px 48px;
            text-align: center;
        }
        .welcome-empty .welcome-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            border-radius: 24px;
            background: linear-gradient(135deg, #c85719 0%, #1460a6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(200,87,25,0.25);
        }
        .welcome-empty .welcome-icon i {
            font-size: 32px;
            color: #fff;
            margin: 0;
        }
        .welcome-empty h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .welcome-empty .welcome-sub {
            font-size: 16px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 32px;
        }
        .welcome-empty .free-credits-card {
            background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 100%);
            border: 1px solid rgba(200,87,25,0.12);
            border-radius: 16px;
            padding: 24px 28px;
            margin-bottom: 32px;
            text-align: left;
        }
        .welcome-empty .free-credits-card .fcc-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .welcome-empty .free-credits-card .fcc-header i {
            font-size: 18px;
            color: var(--accent);
            margin: 0;
        }
        .welcome-empty .free-credits-card .fcc-header span {
            font-size: 15px;
            font-weight: 700;
            color: var(--accent);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .welcome-empty .free-credits-card .fcc-details {
            display: flex;
            gap: 20px;
        }
        .welcome-empty .free-credits-card .fcc-detail {
            flex: 1;
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        .welcome-empty .free-credits-card .fcc-detail .fcc-num {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
        }
        .welcome-empty .free-credits-card .fcc-detail .fcc-label {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .welcome-empty .welcome-cta {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 36px;
            border-radius: 14px;
            background: linear-gradient(135deg, #c85719 0%, #a84615 100%);
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            box-shadow: 0 4px 16px rgba(200,87,25,0.3);
            transition: all 0.2s ease;
        }
        .welcome-empty .welcome-cta:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(200,87,25,0.4);
        }
        .welcome-empty .welcome-steps {
            display: flex;
            gap: 16px;
            margin-top: 36px;
            text-align: left;
        }
        .welcome-empty .welcome-step {
            flex: 1;
            padding: 16px;
            border-radius: 12px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
        }
        .welcome-empty .welcome-step .ws-num {
            width: 24px;
            height: 24px;
            border-radius: 8px;
            background: var(--accent);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }
        .welcome-empty .welcome-step .ws-title {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 2px;
        }
        .welcome-empty .welcome-step .ws-desc {
            font-size: 11px;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        /* Visual cue pulse animation */
        @keyframes cue-pulse {
            0% { box-shadow: 0 0 0 0 rgba(200,87,25,0.5); }
            70% { box-shadow: 0 0 0 12px rgba(200,87,25,0); }
            100% { box-shadow: 0 0 0 0 rgba(200,87,25,0); }
        }
        .pulse-cue {
            animation: cue-pulse 1.8s ease-in-out infinite;
        }

        /* Detail View */
        .detail-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }

        /* Mobile: the toolbar has many buttons — wrap them instead of forcing a
           horizontal scroll of the whole page. */
        @media (max-width: 768px) {
            .detail-header { flex-wrap: wrap; gap: 10px; }
            .detail-title { flex: 1 1 auto; min-width: 0; }
            .detail-title h1 { overflow-wrap: anywhere; }
            .detail-header > div:last-child { flex-wrap: wrap; width: 100%; }
        }

        .back-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: rgba(0,0,0,0.05);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all var(--transition);
            font-size: 18px;
            color: var(--text);
        }
        .back-btn:hover { background: rgba(0,0,0,0.1); }

        .detail-title {
            flex: 1;
        }

        .detail-title h1 {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        .detail-title .detail-sub {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            padding: 16px;
            text-align: center;
        }

        .stat-card.clickable {
            cursor: pointer;
            transition: all var(--transition);
        }
        .stat-card.clickable:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        .stat-card.clickable.active-filter {
            border-color: var(--accent);
            box-shadow: 0 0 0 2px rgba(200,87,25,0.15);
        }

        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        .stat-card.accent .stat-value { color: var(--accent); }
        .stat-card.green .stat-value { color: var(--green); }
        .stat-card.orange .stat-value { color: var(--orange); }
        .stat-card.red .stat-value { color: var(--red); }

        /* Toolbar */
        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .search-box {
            flex: 1;
            min-width: 240px;
            position: relative;
        }

        .search-box i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            font-size: 14px;
        }

        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--card-border);
            background: var(--card-solid);
            font-size: 15px;
            font-family: inherit;
            outline: none;
            transition: border-color var(--transition);
        }
        .search-box input:focus { border-color: var(--accent); }

        .filter-pills {
            display: flex;
            gap: 4px;
            background: rgba(0,0,0,0.04);
            padding: 3px;
            border-radius: var(--radius-xs);
        }

        .filter-pill {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            background: transparent;
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .filter-pill.active {
            background: var(--card-solid);
            color: var(--text);
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
        }

        /* List Map */
        #listMap { z-index:1; }
        .leaflet-draw-toolbar a { background-color:#fff !important; }
        .leaflet-draw-toolbar a:hover { background-color:#f0f0f0 !important; }

        /* Leads Table */
        .leads-table-wrap {
            background: var(--card);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            border: 1px solid var(--card-border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .leads-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leads-table th {
            text-align: left;
            padding: 12px 16px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--card-border);
            background: rgba(0,0,0,0.02);
            white-space: nowrap;
        }

        .leads-table td {
            padding: 12px 16px;
            font-size: 14px;
            border-bottom: 1px solid rgba(0,0,0,0.03);
            vertical-align: middle;
        }

        .leads-table tr:last-child td { border-bottom: none; }

        .leads-table tbody tr {
            transition: background var(--transition);
        }
        .leads-table tbody tr:hover { background: rgba(200,87,25,0.03); }

        .lead-name {
            font-weight: 600;
            color: var(--text);
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .lead-location {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .lead-contact-icons {
            display: flex;
            gap: 6px;
        }

        .contact-icon {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            cursor: pointer;
            transition: all var(--transition);
            border: none;
        }

        .contact-icon.has { background: rgba(200,87,25,0.1); color: var(--accent); }
        .contact-icon.has:hover { background: rgba(200,87,25,0.2); }
        .contact-icon.none { background: rgba(0,0,0,0.04); color: var(--text-tertiary); }
        .contact-icon.tracked { background: rgba(52,199,89,0.15); color: var(--green); }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }

        .status-badge.cold { background: rgba(200,87,25,0.08); color: var(--accent); }
        .status-badge.warm { background: rgba(202,148,42,0.1); color: var(--orange); }
        .status-badge.hot { background: rgba(255,59,48,0.1); color: var(--red); }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            padding: 20px 16px;
        }

        .page-btn {
            min-width: 36px;
            height: 36px;
            padding: 0 8px;
            border-radius: var(--radius-xs);
            border: none;
            background: transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-secondary);
            transition: all var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .page-btn:hover { background: rgba(0,0,0,0.05); }
        .page-btn.active { background: var(--accent); color: white; }
        .page-btn:disabled { opacity: 0.3; cursor: default; }

        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }

        .modal {
            background: var(--card-solid);
            border-radius: 20px;
            width: 100%;
            max-width: 640px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            display: flex;
            flex-direction: column;
        }
        .modal.wide { max-width: 85vw; height: 85vh; }

        .modal-header {
            padding: 24px 24px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }

        .modal-header h2 {
            font-size: 22px;
            font-weight: 700;
        }

        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--card-border);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-shrink: 0;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-xs);
            font-size: 15px;
            font-family: inherit;
            background: var(--bg);
            outline: none;
            transition: border-color var(--transition);
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus { border-color: var(--accent); }

        .form-group textarea { resize: vertical; min-height: 80px; }

        /* Country Picker */
        .country-pick {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border: 2px solid var(--card-border);
            border-radius: var(--radius-sm);
            background: var(--card-bg);
            cursor: pointer;
            font-family: inherit;
            transition: all 0.15s;
        }
        .country-pick:hover { border-color: var(--accent); background: rgba(0,122,255,0.03); }
        .country-pick.active { border-color: var(--accent); background: rgba(0,122,255,0.06); box-shadow: 0 0 0 1px var(--accent); }
        .country-pick-label { font-size: 14px; font-weight: 600; color: var(--text-primary); }
        .country-pick.active .country-pick-label { color: var(--accent); }

        /* State/City Selector */
        .selector-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-top: 12px;
        }

        .selector-panel {
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            overflow: hidden;
            max-height: 280px;
            display: flex;
            flex-direction: column;
        }

        .selector-header {
            padding: 10px 14px;
            background: rgba(0,0,0,0.02);
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 13px;
            font-weight: 600;
        }

        .selector-list {
            overflow-y: auto;
            padding: 8px;
            flex: 1;
        }

        .selector-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            transition: background var(--transition);
        }
        .selector-item:hover { background: rgba(0,0,0,0.03); }

        .selector-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
        }

        .selector-item .searched-badge {
            margin-left: auto;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 4px;
            background: rgba(52,199,89,0.1);
            color: var(--green);
            font-weight: 600;
        }
        .city-filter-pill {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            border: 1px solid var(--card-border);
            background: var(--bg);
            color: var(--text-secondary);
            cursor: pointer;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.15s;
        }
        .city-filter-pill.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
        }

        /* Progress */
        .progress-container {
            margin-top: 16px;
        }

        .progress-bar-bg {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: 3px;
            background: var(--accent);
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
        }

        /* Lead Detail Slide */
        .slide-panel {
            position: fixed;
            right: -500px;
            top: 0;
            bottom: 0;
            width: 480px;
            max-width: 100vw;
            background: var(--card-solid);
            box-shadow: -4px 0 32px rgba(0,0,0,0.12);
            z-index: 1001;
            transition: right 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
            display: flex;
            flex-direction: column;
        }
        .slide-panel.open { right: 0; }

        .slide-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.3);
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }
        .slide-backdrop.open { opacity: 1; pointer-events: auto; }

        .slide-header {
            padding: 24px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
        }

        .slide-body {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
        }

        .slide-section {
            margin-bottom: 24px;
        }

        .slide-section-title {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .info-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            font-size: 14px;
        }

        .info-row i {
            width: 20px;
            text-align: center;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .info-row a {
            color: var(--accent);
            text-decoration: none;
        }
        .info-row a:hover { text-decoration: underline; }

        .tracking-toggles {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .tracking-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: var(--bg);
            border-radius: var(--radius-xs);
        }

        .tracking-toggle-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 500;
        }

        .toggle-switch {
            position: relative;
            width: 48px;
            height: 28px;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--bg-secondary);
            border-radius: 14px;
            cursor: pointer;
            transition: background var(--transition);
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: white;
            bottom: 3px;
            left: 3px;
            transition: transform var(--transition);
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
        }

        .toggle-switch input:checked + .toggle-slider { background: var(--green); }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

        .notes-area {
            width: 100%;
            min-height: 100px;
            padding: 12px;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-xs);
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            outline: none;
            background: var(--bg);
        }
        .notes-area:focus { border-color: var(--accent); }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: var(--text);
            color: white;
            padding: 12px 24px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            z-index: 2000;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-lg);
        }
        .toast.show { transform: translateX(-50%) translateY(0); }

        /* Checkbox */
        .checkbox-wrap input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.25); }

        .hidden { display: none !important; }

        /* Pipeline stage badges */
        .pipeline-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: all var(--transition);
        }
        .pipeline-badge:hover { filter: brightness(0.95); }
        .pipeline-badge.stage-new { background: rgba(0,0,0,0.05); color: var(--text-secondary); }
        .pipeline-badge.stage-contacted { background: rgba(200,87,25,0.1); color: var(--accent); }
        .pipeline-badge.stage-engaged { background: rgba(202,148,42,0.1); color: var(--orange); }
        .pipeline-badge.stage-client { background: rgba(52,199,89,0.12); color: var(--green); }
        .pipeline-badge.stage-no_response { background: rgba(255,59,48,0.08); color: var(--red); }

        /* Follow-up counter */
        .followup-counter {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .followup-counter .count { min-width: 18px; text-align: center; }
        .followup-btn {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 1px solid var(--card-border);
            background: var(--card-solid);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--text-secondary);
            transition: all var(--transition);
        }
        .followup-btn:hover { background: var(--accent-light); color: var(--accent); border-color: var(--accent); }

        /* Outreach icons in table */
        .outreach-icons {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .outreach-icon {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            cursor: pointer;
            transition: all var(--transition);
            border: none;
        }
        .outreach-icon.done { background: rgba(52,199,89,0.15); color: var(--green); }
        .outreach-icon.not-done { background: rgba(0,0,0,0.04); color: var(--text-tertiary); }
        .outreach-icon.not-done:hover { background: rgba(0,0,0,0.08); }

        .enrich-dot {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            border: 1.5px solid #fff;
        }
        .enrich-done { background: var(--green); }
        .enrich-working { background: var(--purple); animation: enrich-pulse 1s ease-in-out infinite; }
        .enrich-queued { background: var(--orange); }
        .enrich-fail { background: var(--red); }
        .enrich-waiting { background: var(--text-tertiary); opacity: 0.5; }
        @keyframes enrich-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        .admin-reenrich-btn {
            background: linear-gradient(135deg, #FF3B30, #FF2D55) !important;
            color: #fff !important;
            border: none !important;
            font-size: 12px;
            padding: 6px 14px;
            font-weight: 600;
        }
        .admin-reenrich-btn:hover { opacity: 0.9; }

        #adminEnrichPanel {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: #fff;
            border-radius: 16px;
            padding: 16px 24px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
            z-index: 99999;
            min-width: 380px;
            font-family: inherit;
            font-size: 13px;
        }
        #adminEnrichPanel .aep-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-weight: 700;
            font-size: 14px;
        }
        #adminEnrichPanel .aep-header i { color: #FF2D55; }
        #adminEnrichPanel .aep-bar {
            height: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 99px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        #adminEnrichPanel .aep-fill {
            height: 100%;
            background: linear-gradient(90deg, #FF3B30, #FF2D55, #ca942a);
            border-radius: 99px;
            width: 0%;
            transition: width 0.4s ease;
        }
        #adminEnrichPanel .aep-stats {
            display: flex;
            gap: 16px;
            font-size: 11px;
            color: rgba(255,255,255,0.7);
        }
        #adminEnrichPanel .aep-stats span { white-space: nowrap; }
        #adminEnrichPanel .aep-stats .num { color: #fff; font-weight: 700; }

        /* GHL Export Full Screen */
        .ghl-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            justify-content: center;
            align-items: center;
        }
        .ghl-overlay.active { display: flex; }
        .ghl-panel {
            width: 92vw; height: 90vh;
            background: var(--card-solid);
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,0.25);
            position: relative;
        }
        .ghl-panel-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .ghl-screen { flex: 1; overflow-y: auto; position: relative; }
        .ghl-toolbar {
            padding: 10px 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            flex-shrink: 0;
        }
        .ghl-spreadsheet-wrap {
            flex: 1;
            overflow: auto;
            padding: 0;
        }
        .ghl-spreadsheet {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .ghl-spreadsheet th {
            padding: 8px 10px;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-tertiary);
            background: var(--bg);
            border-bottom: 2px solid var(--card-border);
            position: sticky;
            top: 0;
            z-index: 2;
            white-space: nowrap;
        }
        .ghl-spreadsheet td {
            padding: 4px 6px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            vertical-align: middle;
        }
        .ghl-spreadsheet tbody tr:hover { background: rgba(200,87,25,0.03); }
        .ghl-spreadsheet .ghl-cell-input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid transparent;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            background: transparent;
            outline: none;
            transition: border-color 0.15s, background 0.15s;
        }
        .ghl-spreadsheet .ghl-cell-input:hover { background: var(--bg); }
        .ghl-spreadsheet .ghl-cell-input:focus {
            border-color: var(--accent);
            background: #fff;
            box-shadow: 0 0 0 2px rgba(200,87,25,0.15);
        }
        .ghl-spreadsheet .ghl-email-cell {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .ghl-spreadsheet .ghl-email-select {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid transparent;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            background: transparent;
            outline: none;
            cursor: pointer;
        }
        .ghl-spreadsheet .ghl-email-select:hover { background: var(--bg); }
        .ghl-spreadsheet .ghl-email-select:focus { border-color: var(--accent); background: #fff; }
        .ghl-bottom-bar {
            padding: 12px 20px;
            border-top: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            gap: 16px;
            flex-wrap: wrap;
            background: var(--bg);
        }
        .ghl-config-section {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .ghl-config-group { display: flex; align-items: center; gap: 8px; }
        .ghl-config-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            white-space: nowrap;
        }
        .ghl-dropdown {
            position: absolute;
            bottom: 100%;
            left: 0;
            right: 0;
            background: var(--card-solid);
            border: 1px solid var(--card-border);
            border-radius: 8px;
            box-shadow: var(--shadow-lg);
            max-height: 200px;
            overflow-y: auto;
            z-index: 10;
            margin-bottom: 4px;
            min-width: 200px;
        }
        .ghl-dropdown .ghl-tag-option {
            padding: 8px 12px;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ghl-dropdown .ghl-tag-option:hover { background: var(--bg); }
        .ghl-tag-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 99px;
            font-size: 11px;
            font-weight: 600;
            background: var(--accent);
            color: #fff;
        }
        .ghl-tag-chip button {
            background: none;
            border: none;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            padding: 0;
            font-size: 10px;
            line-height: 1;
        }
        .ghl-tag-chip button:hover { color: #fff; }
        .ghl-settings-slide {
            position: absolute;
            top: 0; right: 0; bottom: 0;
            width: 360px;
            background: var(--card-solid);
            border-left: 1px solid var(--card-border);
            box-shadow: -8px 0 30px rgba(0,0,0,0.1);
            z-index: 20;
            overflow-y: auto;
        }
        .ghl-log-card {
            background: var(--bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .ghl-log-card:hover { border-color: var(--accent); }
        .ghl-log-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 99px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .ghl-log-status.running { background: #DBEAFE; color: #1E40AF; }
        .ghl-log-status.completed { background: #DCFCE7; color: #166534; }
        .ghl-log-status.failed { background: #FEE2E2; color: #991B1B; }
        .ghl-log-status.cancelled { background: #F3F4F6; color: #6B7280; }
        .ghl-log-status.paused { background: #FEF3C7; color: #92400E; }
        .ghl-log-status.pending { background: #EDE9FE; color: #5B21B6; }
        .ghl-log-progress {
            height: 4px;
            background: var(--card-border);
            border-radius: 99px;
            overflow: hidden;
            margin-top: 8px;
        }
        .ghl-log-progress-bar {
            height: 100%;
            border-radius: 99px;
            background: var(--accent);
            transition: width 0.3s;
        }
        .ghl-socials-cell { display: flex; gap: 3px; flex-wrap: wrap; }
        .ghl-socials-cell a {
            width: 22px; height: 22px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 4px;
            font-size: 10px;
            color: var(--text-secondary);
            background: var(--bg);
            text-decoration: none;
        }
        .ghl-socials-cell a:hover { color: var(--accent); background: rgba(200,87,25,0.1); }
        #ghlEditorScreen { display: flex; flex-direction: column; overflow: hidden; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Make leads table scroll horizontally */
        .leads-table-wrap { overflow-x: auto; }

        /* Pipeline stats section */
        /* Pipeline visual funnel */
        .pipeline-funnel {
            display: flex;
            align-items: stretch;
            margin-bottom: 16px;
            background: var(--card);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            overflow: hidden;
        }
        .pipeline-funnel-stage {
            flex: 1;
            padding: 14px 10px;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition);
            position: relative;
            min-width: 0;
        }
        .pipeline-funnel-stage:not(:last-child)::after {
            content: '';
            position: absolute;
            right: 0;
            top: 20%;
            height: 60%;
            width: 1px;
            background: var(--card-border);
        }
        .pipeline-funnel-stage:hover { background: rgba(0,0,0,0.02); }
        .pipeline-funnel-stage.active-filter { background: rgba(200,87,25,0.05); box-shadow: inset 0 -3px 0 var(--accent); }
        .pipeline-funnel-stage .pf-count { font-size: 20px; font-weight: 700; line-height: 1; }
        .pipeline-funnel-stage .pf-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); margin-top: 4px; }
        .pipeline-funnel-stage .pf-bar { height: 4px; border-radius: 2px; margin-top: 8px; transition: width 0.4s ease; }
        .pipeline-funnel-arrow {
            display: flex;
            align-items: center;
            color: var(--text-tertiary);
            font-size: 10px;
            padding: 0 2px;
            flex-shrink: 0;
        }

        /* Email popover tooltip */
        .email-popover {
            position: absolute;
            z-index: 100;
            background: var(--card-solid);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-xs);
            box-shadow: var(--shadow-lg);
            padding: 6px;
            min-width: 220px;
            max-width: 320px;
        }
        .email-popover-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 7px 10px;
            border-radius: 6px;
            transition: background 0.12s;
        }
        .email-popover-item:hover { background: var(--accent-light); }
        .email-popover-item .ep-email {
            font-size: 13px;
            color: var(--text);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
            flex: 1;
        }
        .email-popover-item .ep-actions {
            display: flex;
            gap: 4px;
            flex-shrink: 0;
        }
        .email-popover-item .ep-btn {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            border: none;
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            color: var(--text-tertiary);
            transition: all 0.12s;
        }
        .email-popover-item .ep-btn:hover { background: var(--accent); color: white; }

        /* Note popover */
        .note-popover {
            position: absolute;
            z-index: 100;
            background: var(--card-solid);
            border: 1px solid var(--card-border);
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-lg);
            width: 300px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .note-popover-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid var(--card-border);
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .note-popover textarea {
            width: 100%;
            border: none;
            outline: none;
            padding: 12px 14px;
            font-size: 13px;
            font-family: inherit;
            resize: none;
            min-height: 100px;
            background: transparent;
            color: var(--text);
        }
        .note-popover-footer {
            display: flex;
            justify-content: flex-end;
            padding: 8px 10px;
            border-top: 1px solid var(--card-border);
            gap: 6px;
        }

        /* Pipeline select dropdown in slide panel */
        .pipeline-select {
            padding: 8px 12px;
            border: 1px solid var(--card-border);
            border-radius: var(--radius-xs);
            font-size: 14px;
            font-family: inherit;
            font-weight: 600;
            background: var(--bg);
            outline: none;
            cursor: pointer;
            width: 100%;
        }
        .pipeline-select:focus { border-color: var(--accent); }

        /* Credits badge */
        .credits-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            background: rgba(200,87,25,0.08);
            color: var(--accent);
            font-size: 13px;
            font-weight: 600;
        }

        /* Searched States summary */
        .searched-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        .searched-tag {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(0,0,0,0.04);
            color: var(--text-secondary);
        }

        /* Website Preview Window */
        .web-preview-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeInOverlay 0.2s ease;
        }
        @keyframes fadeInOverlay {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .web-preview-window {
            width: 85%;
            height: 85%;
            background: var(--card-solid);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 24px 80px rgba(0,0,0,0.35);
            display: flex;
            flex-direction: column;
            animation: scaleIn 0.25s cubic-bezier(0.25, 0.1, 0.25, 1);
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }

        .web-preview-titlebar {
            height: 44px;
            background: #f0f0f0;
            border-bottom: 1px solid #d5d5d5;
            display: flex;
            align-items: center;
            padding: 0 16px;
            gap: 8px;
            flex-shrink: 0;
            user-select: none;
        }

        .titlebar-dots {
            display: flex;
            gap: 7px;
        }

        .titlebar-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            transition: filter 0.15s ease;
        }
        .titlebar-dot:hover { filter: brightness(0.85); }
        .dot-close { background: #ff5f57; }
        .dot-minimize { background: #febc2e; }
        .dot-maximize { background: #28c840; }

        .titlebar-url {
            flex: 1;
            background: #ffffff;
            border: 1px solid #d5d5d5;
            border-radius: 6px;
            padding: 5px 12px;
            font-size: 12px;
            color: var(--text-secondary);
            text-align: center;
            margin: 0 40px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .titlebar-actions {
            display: flex;
            gap: 6px;
        }

        .titlebar-btn {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            font-size: 13px;
            transition: background 0.15s;
        }
        .titlebar-btn:hover { background: rgba(0,0,0,0.08); }

        .preview-links-bar {
            display: flex;
            gap: 2px;
            padding: 2px;
            background: #e8e8e8;
            border-radius: 8px;
        }
        .preview-link-btn {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            border: none;
            background: transparent;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-size: 14px;
            transition: all 0.15s;
        }
        .preview-link-btn:hover { background: rgba(255,255,255,0.6); color: #333; }
        .preview-link-btn.active { background: #fff; color: var(--accent); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

        .web-preview-window iframe {
            flex: 1;
            width: 100%;
            border: none;
            background: white;
        }

        /* Gallery */
        .gallery-thumb {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            cursor: pointer;
            flex-shrink: 0;
            transition: transform 0.15s ease;
            border: 1px solid var(--card-border);
        }
        .gallery-thumb:hover { transform: scale(1.05); }

        .gallery-lightbox {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.92);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .gallery-lightbox img {
            max-width: 90%;
            max-height: 85vh;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            object-fit: contain;
        }
        .lb-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            font-size: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .lb-nav:hover { background: rgba(255,255,255,0.3); transform: translateY(-50%) scale(1.1); }
        .lb-prev { left: 20px; }
        .lb-next { right: 20px; }
        .lb-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            color: white;
            font-size: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .lb-close:hover { background: rgba(255,255,255,0.3); }
        .lb-counter {
            position: absolute;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.7);
            font-size: 14px;
            font-weight: 500;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(10px);
            padding: 6px 16px;
            border-radius: 99px;
        }
        .lb-thumbstrip {
            position: absolute;
            bottom: 56px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 6px;
            max-width: 80%;
            overflow-x: auto;
            padding: 6px;
            border-radius: 10px;
            background: rgba(0,0,0,0.3);
            backdrop-filter: blur(10px);
        }
        .lb-thumb {
            width: 52px;
            height: 52px;
            border-radius: 6px;
            object-fit: cover;
            cursor: pointer;
            opacity: 0.5;
            transition: all 0.2s;
            flex-shrink: 0;
            border: 2px solid transparent;
        }
        .lb-thumb:hover { opacity: 0.8; }
        .lb-thumb.active { opacity: 1; border-color: white; }

        /* Folder color variants */
        .folder-blue { background: linear-gradient(135deg, #c85719, #1460a6); color: white; }
        .folder-purple { background: linear-gradient(135deg, #337f83, #1460a6); color: white; }
        .folder-green { background: linear-gradient(135deg, #34C759, #30D158); color: white; }
        .folder-orange { background: linear-gradient(135deg, #ca942a, #c85719); color: white; }
        .folder-pink { background: linear-gradient(135deg, #FF2D55, #FF375F); color: white; }
        .folder-teal { background: linear-gradient(135deg, #1460a6, #337f83); color: white; }

        @media (max-width: 768px) {
            .container { padding: 20px 16px; }
            .folder-grid { grid-template-columns: 1fr; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .selector-grid { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; }
            .search-box { min-width: 100%; }
            .slide-panel { width: 100%; }
        }
    </style>
</head>
<body>

<!-- LISTS VIEW -->
<div id="listsView" class="container">
    <div class="page-header">
        <div>
            <h1>Lead Lists</h1>
            <div class="subtitle">Organize and manage your leads</div>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
            <span class="credits-badge"><i class="fas fa-coins"></i> <span id="creditsDisplay"><?php echo number_format($userCredits); ?></span> credits</span>
            <button class="btn btn-primary" onclick="app.openCreateModal()"><i class="fas fa-plus"></i> New List</button>
        </div>
    </div>
    <?php
    $lowCredit = $userCredits <= 50;
    ob_start(); ?>
    <div class="low-credit-banner" style="<?php echo $lowCredit ? 'display:flex;' : 'display:none;'; ?>align-items:center;justify-content:space-between;gap:14px;background:linear-gradient(135deg,#fff3ec,#ffe7d7);border:1px solid #f0c9ad;border-radius:14px;padding:13px 18px;margin-bottom:18px;">
        <div style="font-size:14px;color:#8a4a1e;line-height:1.4;">
            <i class="fas fa-bolt" style="margin-right:6px;"></i>
            You have <strong class="lc-count"><?php echo number_format($userCredits); ?></strong> credits left — that's <strong class="lc-count"><?php echo number_format($userCredits); ?></strong> more leads. Upgrade to keep searching.
        </div>
        <button onclick="app.showUpgradePrompt(1)" style="background:#c85719;color:#fff;border:none;border-radius:10px;padding:9px 20px;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;white-space:nowrap;">Upgrade</button>
    </div>
    <?php $lowCreditBannerHtml = ob_get_clean(); echo $lowCreditBannerHtml; ?>
    <div id="folderGrid" class="folder-grid"></div>
    <div id="emptyState" class="empty-state hidden">
        <div class="welcome-empty">
            <div class="welcome-icon"><i class="fas fa-rocket"></i></div>
            <h2>Welcome to Lead Lists</h2>
            <p class="welcome-sub">Each lead a search returns costs <strong>1 credit</strong>, and enriching them for email &amp; socials is <strong>free</strong>. Create your first list and start building your pipeline.</p>

            <div class="free-credits-card">
                <div class="fcc-header">
                    <i class="fas fa-coins"></i>
                    <span>Your Plan</span>
                </div>
                <div class="fcc-details">
                    <div class="fcc-detail">
                        <div class="fcc-num" id="freeCreditsNum"><?php echo number_format($userCredits); ?></div>
                        <div class="fcc-label">Credits Available</div>
                    </div>
                    <div class="fcc-detail">
                        <div class="fcc-num">1</div>
                        <div class="fcc-label">Credit Per Lead</div>
                    </div>
                    <div class="fcc-detail">
                        <div class="fcc-num">Free</div>
                        <div class="fcc-label">Email Enrichment</div>
                    </div>
                </div>
            </div>

            <button class="welcome-cta pulse-cue" onclick="app.openCreateModal()">
                <i class="fas fa-plus"></i> Create Your First List
            </button>

            <div class="welcome-steps">
                <div class="welcome-step">
                    <div class="ws-num">1</div>
                    <div class="ws-title">Create a list</div>
                    <div class="ws-desc">Name your list and pick the cities you want to search</div>
                </div>
                <div class="welcome-step">
                    <div class="ws-num">2</div>
                    <div class="ws-title">Find leads</div>
                    <div class="ws-desc">Search any business type and get up to 500 results per city</div>
                </div>
                <div class="welcome-step">
                    <div class="ws-num">3</div>
                    <div class="ws-title">Track outreach</div>
                    <div class="ws-desc">Mark emails, DMs, follow-ups and watch your pipeline grow</div>
                </div>
            </div>

            <style>
              .welcome-faqs{max-width:660px;margin:34px auto 0;text-align:left}
              .welcome-faqs h3{font-size:16px;font-weight:800;text-align:center;margin-bottom:14px;color:var(--text-primary,#141517)}
              .wfaq{border:1px solid var(--card-border,#e5e7eb);border-radius:12px;background:var(--card-solid,#fff);margin-bottom:10px;overflow:hidden}
              .wfaq summary{list-style:none;cursor:pointer;padding:14px 16px;font-weight:700;font-size:14px;color:var(--text-primary,#141517);display:flex;align-items:center;justify-content:space-between;gap:12px}
              .wfaq summary::-webkit-details-marker{display:none}
              .wfaq summary::after{content:"\f078";font-family:"Font Awesome 6 Free";font-weight:900;font-size:12px;color:var(--accent,#c85719);transition:transform .2s;flex:none}
              .wfaq[open] summary::after{transform:rotate(180deg)}
              .wfaq .wfaq-body{padding:0 16px 14px;font-size:13.5px;line-height:1.6;color:var(--text-secondary,#5b6066)}
            </style>
            <div class="welcome-faqs">
              <h3>Frequently asked questions</h3>
              <details class="wfaq"><summary>Does every lead come with an email address?</summary><div class="wfaq-body">No &mdash; leads are pulled live from Google Maps, so every lead includes the business name, phone, website, rating and address, but not every business lists an email publicly. Our free one-click enrichment then fills in emails &amp; socials wherever they&rsquo;re available, so some leads simply won&rsquo;t have an email.</div></details>
              <details class="wfaq"><summary>What does a search cost?</summary><div class="wfaq-body">1 credit per lead returned. Enriching leads with emails &amp; socials is always free &mdash; and so is exporting.</div></details>
              <details class="wfaq"><summary>What data do I get for each lead?</summary><div class="wfaq-body">Business name, phone number, website, rating, address and category &mdash; plus emails and social profiles after free enrichment.</div></details>
              <details class="wfaq"><summary>Where does the lead data come from?</summary><div class="wfaq-body">Live from Google Maps&rsquo; public business listings &mdash; fresh every search, never a recycled or resold list.</div></details>
              <details class="wfaq"><summary>How many leads can I pull at once?</summary><div class="wfaq-body">Up to 500 results per city. Select multiple cities or whole states to pull thousands in a single search.</div></details>
              <details class="wfaq"><summary>Can I export my leads?</summary><div class="wfaq-body">Yes &mdash; use the Export menu to download a CSV, or push your leads straight into your Free CRM.</div></details>
              <details class="wfaq"><summary>What if a search returns no leads?</summary><div class="wfaq-body">You&rsquo;re only charged for leads actually returned, so a search that finds nothing costs you no credits.</div></details>
            </div>
        </div>
    </div>
</div>

<!-- DETAIL VIEW -->
<div id="detailView" class="container hidden">
    <?php echo $lowCreditBannerHtml; ?>
    <div class="detail-header">
        <button class="back-btn" onclick="app.goBack()"><i class="fas fa-arrow-left"></i></button>
        <div class="detail-title">
            <h1 id="detailName"></h1>
            <div class="detail-sub" id="detailSub"></div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <span class="credits-badge"><i class="fas fa-coins"></i> <span id="creditsDisplay2"><?php echo number_format($userCredits); ?></span> credits</span>
            <button class="btn btn-sm" onclick="app.openEmailTemplateModal()" style="background:var(--accent);color:#fff;font-size:12px;padding:6px 12px;border:none;" title="Email Template"><i class="fas fa-envelope"></i> Template</button>
            <button class="btn btn-sm" onclick="app.openShareModal()" style="background:var(--bg);border:1px solid var(--card-border);color:var(--text-primary);font-size:12px;padding:6px 12px;" title="Share"><i class="fas fa-share-alt"></i> Share</button>
            <button class="btn btn-primary" onclick="app.openAddLeadsModal()"><i class="fas fa-plus"></i> Add Leads</button>
            <button class="btn btn-sm admin-reenrich-btn" onclick="app.forceReenrich()" title="Re-enrich all websites in this folder"><i class="fas fa-sync-alt"></i> Re-Enrich All</button>
            <button class="btn btn-sm" onclick="app.openFolderImportLogs()" style="background:var(--bg);border:1px solid var(--card-border);color:var(--text-primary);font-size:12px;padding:6px 12px;" title="Free CRM Import Logs"><i class="fas fa-history"></i> Import Logs</button>
            <button class="btn btn-sm" onclick="document.getElementById('importCsvFile').click()" style="background:var(--bg);border:1px solid var(--card-border);color:var(--text-primary);font-size:12px;padding:6px 12px;" title="Import CSV"><i class="fas fa-upload"></i> Import CSV</button>
            <input type="file" id="importCsvFile" accept=".csv,text/csv" style="display:none;" onchange="app.handleImportCSV(this)">
            <div style="position:relative;">
                <button class="btn btn-secondary" id="exportMenuBtn" onclick="app.toggleExportMenu()"><i class="fas fa-download"></i> Export</button>
                <div id="exportMenu" class="hidden" style="position:absolute;top:100%;right:0;margin-top:6px;background:var(--card-solid);border:1px solid var(--card-border);border-radius:var(--radius-xs);box-shadow:var(--shadow-lg);min-width:200px;z-index:100;padding:6px;">
                    <button class="btn btn-ghost" style="width:100%;justify-content:flex-start;" onclick="app.openExportPreview('csv')"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <button class="btn btn-ghost" style="width:100%;justify-content:flex-start;text-align:left;" onclick="app.openGHLExport()"><i class="fas fa-paper-plane"></i> Export to Free CRM</button>
                </div>
            </div>
        </div>
    </div>

    <div id="searchedStatesArea" class="hidden" style="margin-bottom:20px;">
        <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:6px;">Searched Areas</div>
        <div id="searchedStates" class="searched-summary"></div>
    </div>

    <div id="topStatsRow"></div>

    <div id="listMapWrap" style="margin-bottom:20px;background:var(--card-solid);border:1px solid var(--card-border);border-radius:var(--radius);overflow:hidden;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1px solid var(--card-border);">
            <div style="font-weight:600;font-size:14px;"><i class="fas fa-map-marked-alt" style="color:var(--accent);margin-right:6px;"></i>Map View</div>
            <div style="display:flex;align-items:center;gap:12px;">
                <span id="listMapCount" style="font-size:12px;color:var(--text-tertiary);">0 locations</span>
                <button onclick="app.toggleListMap()" id="listMapToggle" style="background:none;border:none;cursor:pointer;font-size:14px;color:var(--text-tertiary);"><i class="fas fa-chevron-up"></i></button>
            </div>
        </div>
        <div id="listMapContainer">
            <div id="listMap" style="width:100%;height:400px;"></div>
        </div>
    </div>

    <div id="statsRow"></div>

    <div class="toolbar">
        <div class="search-box">
            <i class="fas fa-search"></i>
            <input type="text" id="leadSearch" placeholder="Search leads..." oninput="app.debounceSearch()">
        </div>
        <div class="filter-pills" id="filterPills">
            <button class="filter-pill active" data-filter="" onclick="app.filterByHas('')">All</button>
            <button class="filter-pill" data-filter="emailed" onclick="app.filterByHas('emailed')">Emailed</button>
            <button class="filter-pill" data-filter="ig_dm" onclick="app.filterByHas('ig_dm')">DM'd</button>
            <button class="filter-pill" data-filter="visited" onclick="app.filterByHas('visited')">Visited</button>
        </div>
        <select id="perPageSelect" onchange="app.changePerPage(this.value)" style="padding:6px 10px;border:1px solid var(--card-border);border-radius:var(--radius-xs);font-size:13px;font-family:inherit;background:var(--card-solid);outline:none;">
            <option value="25">25 / page</option>
            <option value="50" selected>50 / page</option>
            <option value="100">100 / page</option>
            <option value="200">200 / page</option>
            <option value="500">500 / page</option>
        </select>
        <div class="hidden" id="bulkActions" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
            <span id="bulkSelectedCount" style="font-size:12px;font-weight:600;color:var(--text-secondary);"></span>
            <button class="btn btn-sm" onclick="app.bulkSetField('outreach_email', 1)" style="background:var(--purple);color:#fff;font-size:11px;padding:4px 10px;"><i class="fas fa-envelope"></i> Emailed</button>
            <button class="btn btn-sm" onclick="app.bulkSetField('outreach_instagram', 1)" style="background:var(--pink);color:#fff;font-size:11px;padding:4px 10px;"><i class="fas fa-comment-dots"></i> DM'd</button>
            <button class="btn btn-sm" onclick="app.bulkSetField('pipeline_stage', 'contacted')" style="background:var(--accent);color:#fff;font-size:11px;padding:4px 10px;"><i class="fas fa-arrow-right"></i> Contacted</button>
            <button class="btn btn-sm" onclick="app.bulkSetField('pipeline_stage', 'engaged')" style="background:var(--orange);color:#fff;font-size:11px;padding:4px 10px;"><i class="fas fa-comments"></i> Engaged</button>
            <button class="btn btn-sm" onclick="app.bulkSetField('pipeline_stage', 'client')" style="background:var(--green);color:#fff;font-size:11px;padding:4px 10px;"><i class="fas fa-handshake"></i> Client</button>
            <button class="btn btn-danger btn-sm" onclick="app.bulkDeleteLeads()" style="font-size:11px;padding:4px 10px;"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>

    <div class="leads-table-wrap">
        <table class="leads-table" style="min-width:1100px;">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="selectAllCheckbox" onchange="app.toggleSelectAll(this.checked)" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;"></th>
                    <th>Business</th>
                    <th>Location</th>
                    <th>Contact</th>
                    <th>Socials</th>
                    <th>Pipeline</th>
                    <th>Outreach</th>
                    <th>Follow-ups</th>
                    <th>First Contact</th>
                    <th style="width:70px;">Actions</th>
                </tr>
            </thead>
            <tbody id="leadsBody"></tbody>
        </table>
        <div id="leadsEmpty" class="empty-state hidden" style="padding:40px;">
            <i class="fas fa-users" style="font-size:40px;"></i>
            <h3 style="font-size:18px;">No leads yet</h3>
            <p>Add leads by selecting states and cities to search</p>
        </div>
    </div>
    <div id="paginationArea" class="pagination"></div>
</div>

<!-- CREATE/EDIT LIST MODAL -->
<div id="createModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-header">
            <h2 id="createModalTitle">New Lead List</h2>
            <button class="btn-icon" onclick="app.closeCreateModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>List Name</label>
                <input type="text" id="listName" placeholder="e.g., Functional Medicine Doctors">
            </div>
            <div class="form-group">
                <label>Description (optional)</label>
                <textarea id="listDesc" placeholder="What kind of leads are in this list?"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="app.closeCreateModal()">Cancel</button>
            <button class="btn btn-primary" id="createListBtn" onclick="app.saveList()">Create</button>
        </div>
    </div>
</div>

<!-- SHARE MODAL -->
<div id="shareModal" class="modal-overlay">
    <div class="modal" style="max-width:460px;">
        <div class="modal-header">
            <h2><i class="fas fa-share-alt" style="color:var(--accent);margin-right:8px;"></i>Share Lead List</h2>
            <button class="btn-icon" onclick="document.getElementById('shareModal').classList.remove('active')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--card-border);">
                <div>
                    <div style="font-weight:600;font-size:14px;">Make Public</div>
                    <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;">Anyone with the link can download this list as CSV</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" id="shareToggle" onchange="app.toggleSharePublic(this.checked)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
            <div id="shareLinkSection" class="hidden" style="margin-top:16px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Share Link</label>
                <div style="display:flex;gap:8px;">
                    <input type="text" id="shareLinkInput" readonly style="flex:1;padding:10px 12px;border:1px solid var(--card-border);border-radius:10px;font-size:13px;font-family:inherit;background:var(--bg);color:var(--text-primary);outline:none;">
                    <button class="btn btn-primary" onclick="app.copyShareLink()" style="padding:10px 16px;white-space:nowrap;"><i class="fas fa-copy"></i> Copy</button>
                </div>
                <p style="font-size:11px;color:var(--text-tertiary);margin-top:8px;"><i class="fas fa-lock" style="margin-right:4px;"></i> Link uses a secure 64-character token that cannot be guessed</p>
                <div style="margin-top:16px;border-top:1px solid var(--card-border);padding-top:16px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div style="font-weight:600;font-size:14px;display:flex;align-items:center;gap:6px;"><i class="fas fa-user-plus" style="color:var(--accent);"></i> Signups from this link</div>
                        <span id="shareClaimCount" style="font-size:12px;font-weight:700;color:var(--accent);background:var(--accent-light);padding:2px 10px;border-radius:8px;">0</span>
                    </div>
                    <div id="shareClaimsList" style="max-height:200px;overflow-y:auto;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- EMAIL TEMPLATE MODAL -->
<div id="emailTemplateModal" class="modal-overlay">
    <div class="modal" style="max-width:560px;">
        <div class="modal-header">
            <h2><i class="fas fa-envelope" style="color:var(--accent);margin-right:8px;"></i>Email Template</h2>
            <button class="btn-icon" onclick="document.getElementById('emailTemplateModal').classList.remove('active')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;line-height:1.5;">Set up your email template once — every time you click an email it will auto-fill your message. Use the buttons below to insert lead info automatically.</p>

            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:16px;">
                <button class="btn btn-sm" style="background:var(--accent-light);color:var(--accent);border:none;font-size:11px;padding:4px 10px;border-radius:8px;" onclick="app.insertTemplateVar('subject', '{business_name}')">{business_name}</button>
                <button class="btn btn-sm" style="background:var(--accent-light);color:var(--accent);border:none;font-size:11px;padding:4px 10px;border-radius:8px;" onclick="app.insertTemplateVar('subject', '{city}')">{city}</button>
                <button class="btn btn-sm" style="background:var(--accent-light);color:var(--accent);border:none;font-size:11px;padding:4px 10px;border-radius:8px;" onclick="app.insertTemplateVar('subject', '{state}')">{state}</button>
                <button class="btn btn-sm" style="background:var(--accent-light);color:var(--accent);border:none;font-size:11px;padding:4px 10px;border-radius:8px;" onclick="app.insertTemplateVar('subject', '{my_name}')">{my_name}</button>
                <button class="btn btn-sm" style="background:var(--accent-light);color:var(--accent);border:none;font-size:11px;padding:4px 10px;border-radius:8px;" onclick="app.insertTemplateVar('subject', '{my_company}')">{my_company}</button>
            </div>

            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Your Name</label>
                <input type="text" id="etMyName" placeholder="e.g. Sarah" style="width:100%;padding:10px 12px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;background:var(--bg);color:var(--text-primary);outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Your Company</label>
                <input type="text" id="etMyCompany" placeholder="e.g. Acme Marketing" style="width:100%;padding:10px 12px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;background:var(--bg);color:var(--text-primary);outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Subject Line</label>
                <input type="text" id="etSubject" placeholder="e.g. Quick question for {business_name}" style="width:100%;padding:10px 12px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;background:var(--bg);color:var(--text-primary);outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:var(--text-secondary);display:block;margin-bottom:6px;">Email Body</label>
                <textarea id="etBody" rows="7" placeholder="Hi there!&#10;&#10;I came across {business_name} in {city} and wanted to reach out...&#10;&#10;Best,&#10;{my_name}" style="width:100%;padding:10px 12px;border:1px solid var(--card-border);border-radius:10px;font-size:14px;font-family:inherit;background:var(--bg);color:var(--text-primary);outline:none;resize:vertical;line-height:1.5;"></textarea>
            </div>

            <div style="display:flex;gap:8px;">
                <button class="btn btn-primary" onclick="app.saveEmailTemplate()" style="flex:1;"><i class="fas fa-save"></i> Save Template</button>
                <button class="btn btn-secondary" onclick="app.clearEmailTemplate()" style="padding:10px 16px;"><i class="fas fa-trash"></i></button>
            </div>
            <p style="font-size:11px;color:var(--text-tertiary);margin-top:10px;text-align:center;"><i class="fas fa-magic" style="margin-right:4px;"></i> Now when you click any email, your template will auto-fill!</p>
        </div>
    </div>
</div>

<!-- UPGRADE MODAL -->
<div id="upgradeModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.4);backdrop-filter:blur(8px);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:36px 32px;max-width:420px;width:90%;text-align:center;box-shadow:0 24px 80px rgba(0,0,0,0.15);">
        <div style="width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#c85719,#1460a6);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <i class="fas fa-rocket" style="color:#fff;font-size:22px;"></i>
        </div>
        <?php
            // Plan-aware upgrade prompt. Show only tiers ABOVE the user's current
            // plan; if they're already on the top plan, promote the 1¢ page instead.
            $planRank = ['none' => 0, 'business' => 1, 'agency' => 2, 'enterprise' => 3];
            $curRank  = $planRank[$userPlan] ?? 0;
            $allPlans = [
                ['id' => STRIPE_PRICE_STARTER,    'name' => 'Starter', 'price' => PLAN_STARTER_PRICE,    'leads' => PLAN_STARTER_CREDITS,    'rank' => 1],
                ['id' => STRIPE_PRICE_GROWTH,     'name' => 'Growth',  'price' => PLAN_GROWTH_PRICE,     'leads' => PLAN_GROWTH_CREDITS,     'rank' => 2],
                ['id' => STRIPE_PRICE_ENTERPRISE, 'name' => 'Pro',     'price' => PLAN_ENTERPRISE_PRICE, 'leads' => PLAN_ENTERPRISE_CREDITS, 'rank' => 3],
            ];
            $planNames  = ['none' => 'Free', 'business' => 'Starter', 'agency' => 'Growth', 'enterprise' => 'Pro'];
            $curName    = $planNames[$userPlan] ?? 'your';
            $upgradePlans = array_values(array_filter($allPlans, function($p) use ($curRank) { return $p['rank'] > $curRank; }));
            $isTopPlan  = ($curRank >= 3) || empty($upgradePlans);
        ?>
        <?php if ($isTopPlan): ?>
        <h2 style="font-size:20px;font-weight:700;margin:0 0 8px;">You&rsquo;re on our biggest plan</h2>
        <p style="color:#6e6e73;font-size:14px;margin:0 0 20px;line-height:1.55;">You have <strong id="upgradeHave">0</strong> credits left and you&rsquo;re already on <strong>Pro</strong> &mdash; our highest tier. The best way to keep pulling leads now is to get them <strong>at cost, for less than 1&cent; each</strong>. Own the software, pull leads directly from the source, and even resell them to your clients.</p>
        <div style="border:1.5px solid #dc2626;border-radius:14px;padding:20px 16px;background:#fff5f5;margin-bottom:6px;">
            <div style="font-weight:800;font-size:16px;color:#b91c1c;margin-bottom:4px;"><i class="fas fa-bolt"></i> Get Leads For Less Than 1&cent;</div>
            <div style="font-size:13px;color:#6e6e73;line-height:1.5;margin-bottom:14px;">The best plan for high-volume users like you &mdash; leads at cost instead of a fixed monthly cap.</div>
            <button onclick="app.gotoPenny()" style="width:100%;padding:12px;border-radius:10px;border:none;cursor:pointer;font-weight:800;font-size:14px;font-family:inherit;background:#dc2626;color:#fff;">Show me how &rarr;</button>
        </div>
        <?php else: ?>
        <h2 style="font-size:20px;font-weight:700;margin:0 0 8px;">Out of credits</h2>
        <p style="color:#6e6e73;font-size:14px;margin:0 0 20px;line-height:1.5;">You have <strong id="upgradeHave">0</strong> credits left<?php echo $curRank > 0 ? ' on the <strong>' . htmlspecialchars($curName) . '</strong> plan' : ''; ?>. Upgrade to keep searching &mdash; <strong>1 credit per lead</strong>, and enrichment is always free.</p>
        <div style="display:grid;grid-template-columns:repeat(<?php echo count($upgradePlans); ?>,1fr);gap:10px;text-align:center;">
            <?php foreach ($upgradePlans as $idx => $pl): $featured = ($idx === 0); ?>
            <div style="border:1.5px solid <?php echo $featured ? '#c85719' : '#e6e6e6'; ?>;border-radius:14px;padding:18px 10px 14px;position:relative;">
                <?php if ($featured): ?><div style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:#c85719;color:#fff;font-size:9px;font-weight:800;letter-spacing:.04em;padding:2px 9px;border-radius:999px;">NEXT TIER</div><?php endif; ?>
                <div style="font-weight:700;font-size:14px;margin-bottom:2px;"><?php echo $pl['name']; ?></div>
                <div style="font-size:24px;font-weight:800;line-height:1.1;">$<?php echo $pl['price']; ?><span style="font-size:12px;color:#98918a;font-weight:500;">/mo</span></div>
                <div style="font-size:12.5px;color:#6e6e73;margin:6px 0 3px;"><?php echo number_format($pl['leads']); ?> leads/mo</div>
                <div style="font-size:11px;color:#98918a;font-weight:600;margin:0 0 14px;"><?php echo number_format($pl['price'] / $pl['leads'] * 100, 1); ?>&cent; per lead</div>
                <button onclick="app.upgradeTo('<?php echo htmlspecialchars($pl['id']); ?>', this)" style="width:100%;padding:10px;border-radius:10px;border:none;cursor:pointer;font-weight:700;font-size:13px;font-family:inherit;background:<?php echo $featured ? '#c85719' : '#f1efec'; ?>;color:<?php echo $featured ? '#fff' : '#1d1d1f'; ?>;">Upgrade</button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:18px;">
            <button onclick="app.closeUpgradeModal()" style="background:none;border:none;color:#6e6e73;font-size:13px;cursor:pointer;font-family:inherit;">Maybe later</button>
        </div>
    </div>
</div>

<!-- ADD LEADS MODAL -->
<div id="addLeadsModal" class="modal-overlay">
    <div class="modal wide">
        <div class="modal-header">
            <h2>Add Leads</h2>
            <button class="btn-icon" onclick="app.closeAddLeadsModal()" title="Minimize"><i class="fas fa-minus"></i></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Search Query</label>
                <input type="text" id="scrapeQuery" placeholder="e.g., functional medicine doctors, dentists near me">
            </div>
            <div class="form-group">
                <label>Results per City</label>
                <select id="scrapeLimit" onchange="app.updateCityCounts()">
                    <option value="5">5</option>
                    <option value="10">10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="100" selected>100</option>
                    <option value="200">200</option>
                    <option value="300">300</option>
                    <option value="400">400</option>
                    <option value="500">500</option>
                    <option value="all">Use all remaining credits</option>
                </select>
            </div>

            <div id="countryPicker" style="display:flex;gap:10px;margin-bottom:14px;">
                <button class="country-pick active" data-country="US" onclick="app.switchCountry('US')">
                    <span style="font-size:22px;">🇺🇸</span>
                    <span class="country-pick-label">United States</span>
                </button>
                <button class="country-pick" data-country="UK" onclick="app.switchCountry('UK')">
                    <span style="font-size:22px;">🇬🇧</span>
                    <span class="country-pick-label">United Kingdom</span>
                </button>
                <button class="country-pick" data-country="EU" onclick="app.switchCountry('EU')">
                    <span style="font-size:22px;">🇪🇺</span>
                    <span class="country-pick-label">Europe</span>
                </button>
            </div>

            <div class="selector-grid">
                <div class="selector-panel">
                    <div class="selector-header">
                        <span id="statesLabel">States</span>
                        <label class="selector-item" style="padding:0;margin:0;">
                            <input type="checkbox" id="selectAllStates" onchange="app.toggleAllStates(this.checked)">
                            <span style="font-size:12px;">All</span>
                        </label>
                    </div>
                    <div style="padding:6px 8px;border-bottom:1px solid var(--card-border);">
                        <input type="text" id="stateSearch" placeholder="Search states..." oninput="app.filterStates()" style="width:100%;padding:6px 10px;border:1px solid var(--card-border);border-radius:6px;font-size:13px;font-family:inherit;outline:none;background:var(--bg);">
                    </div>
                    <div class="selector-list" id="statesList"></div>
                </div>
                <div class="selector-panel">
                    <div class="selector-header" style="gap:6px;flex-wrap:wrap;">
                        <span>Cities <span id="citiesCount" style="color:var(--text-tertiary);font-weight:400;"></span></span>
                        <div style="display:flex;gap:3px;align-items:center;margin-left:auto;">
                            <button class="city-filter-pill active" data-cityfilter="all" onclick="app.setCityFilter('all')">All</button>
                            <button class="city-filter-pill" data-cityfilter="searched" onclick="app.setCityFilter('searched')">Searched</button>
                            <button class="city-filter-pill" data-cityfilter="unsearched" onclick="app.setCityFilter('unsearched')">New</button>
                            <label class="selector-item" style="padding:0;margin:0 0 0 4px;">
                                <input type="checkbox" id="selectAllCities" onchange="app.toggleAllCities(this.checked)">
                                <span style="font-size:11px;">Select All</span>
                            </label>
                        </div>
                        <div style="display:flex;gap:3px;align-items:center;width:100%;margin-top:4px;">
                            <span style="font-size:11px;color:var(--text-tertiary);margin-right:4px;">Sort:</span>
                            <button class="city-filter-pill active" data-citysort="name" onclick="app.setCitySort('name')">A-Z</button>
                            <button class="city-filter-pill" data-citysort="pop_desc" onclick="app.setCitySort('pop_desc')">Most Pop</button>
                            <button class="city-filter-pill" data-citysort="pop_asc" onclick="app.setCitySort('pop_asc')">Least Pop</button>
                        </div>
                    </div>
                    <div style="padding:6px 8px;border-bottom:1px solid var(--card-border);">
                        <input type="text" id="citySearch" placeholder="Search cities..." oninput="app.filterCities()" style="width:100%;padding:6px 10px;border:1px solid var(--card-border);border-radius:6px;font-size:13px;font-family:inherit;outline:none;background:var(--bg);">
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 14px;font-size:10.5px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--text-tertiary);border-bottom:1px solid var(--card-border);">
                        <span>City</span>
                        <span>Population</span>
                    </div>
                    <div class="selector-list" id="citiesList">
                        <div style="padding:20px;text-align:center;color:var(--text-tertiary);font-size:13px;">Select a state or region to see cities</div>
                    </div>
                </div>
            </div>

            <div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <div style="font-size:13px;color:var(--text-secondary);">
                    <span id="selectedCitiesCount">0</span> cities selected &middot;
                    up to <strong style="color:var(--accent);"><span id="estimatedCredits">0</span> leads</strong>
                    &middot; <span style="opacity:.75;">1 credit per lead returned · enrichment is free</span>
                </div>
                <div style="font-size:13px;font-weight:700;color:var(--accent);white-space:nowrap;">
                    <i class="fas fa-coins"></i> <span class="lc-count"><?php echo number_format($userCredits); ?></span> credits left
                </div>
            </div>

            <div id="scrapeProgress" class="progress-container hidden">
                <div style="margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <i class="fas fa-map-marker-alt" style="color:var(--accent);"></i>
                        <span style="font-size:13px;font-weight:600;">Step 1: Finding Leads</span>
                        <span id="scrapeStep1Status" style="margin-left:auto;font-size:12px;color:var(--text-secondary);"></span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="scrapeProgressBar"></div>
                    </div>
                    <div class="progress-text">
                        <span id="scrapeProgressText">Starting...</span>
                        <span id="scrapeProgressCount"></span>
                    </div>
                    <div id="scrapeActivityLog" style="margin-top:10px;max-height:150px;overflow-y:auto;font-size:12px;background:var(--bg-secondary);border-radius:8px;padding:8px 10px;display:none;">
                    </div>
                </div>
                <div id="aiScrapeSection" style="opacity:0.4;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                        <i class="fas fa-robot" style="color:var(--purple);"></i>
                        <span style="font-size:13px;font-weight:600;">Step 2: AI Enrichment</span>
                        <span id="aiScrapeStatus" style="margin-left:auto;font-size:12px;color:var(--text-secondary);">Waiting...</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="aiProgressBar" style="background:var(--purple);"></div>
                    </div>
                    <div class="progress-text">
                        <span id="aiProgressText" style="font-size:12px;">Finding emails &amp; social media from each website using AI</span>
                        <span id="aiProgressCount" style="font-size:12px;"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancelScrapeBtn" onclick="app.closeAddLeadsModal()">Cancel</button>
            <button class="btn btn-primary" id="startScrapeBtn" onclick="app.startBulkScrape()"><i class="fas fa-rocket"></i> Start Search</button>
            <button class="btn btn-primary hidden" id="closeScrapeBtn" onclick="app.finishAndClose()"><i class="fas fa-check"></i> Done — Minimize</button>
        </div>
    </div>
</div>

<!-- LEAD DETAIL SLIDE PANEL -->
<div id="slideBackdrop" class="slide-backdrop" onclick="app.closeLeadDetail()"></div>
<div id="slidePanel" class="slide-panel">
    <div class="slide-header">
        <div>
            <div id="slideLeadName" style="font-size:20px;font-weight:700;"></div>
            <div id="slideLeadLocation" style="font-size:13px;color:var(--text-secondary);margin-top:4px;"></div>
        </div>
        <button class="btn-icon" onclick="app.closeLeadDetail()"><i class="fas fa-times"></i></button>
    </div>
    <div class="slide-body">
        <div id="slideGallerySection" class="slide-section hidden">
            <div id="slideGallery" style="display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;"></div>
        </div>
        <div id="slideMapSection" class="slide-section hidden">
            <div id="slideMap" style="width:100%;height:200px;border-radius:var(--radius-xs);overflow:hidden;border:1px solid var(--card-border);"></div>
        </div>
        <div class="slide-section">
            <div class="slide-section-title">Contact Information</div>
            <div id="slideContact"></div>
        </div>
        <div class="slide-section">
            <div class="slide-section-title">Social Media</div>
            <div id="slideSocials"></div>
        </div>
        <div class="slide-section">
            <div class="slide-section-title">Notes</div>
            <textarea class="notes-area" id="slideNotes" placeholder="Add notes about this lead..."></textarea>
            <button class="btn btn-primary btn-sm" style="margin-top:8px;" onclick="app.saveNotes()"><i class="fas fa-save"></i> Save Notes</button>
        </div>
        <div class="slide-section">
            <div class="slide-section-title">Details</div>
            <div id="slideDetails"></div>
        </div>
        <div class="hidden">
            <select id="slidePipelineStage"></select>
            <input type="checkbox" id="slideOutreachEmail">
            <input type="checkbox" id="slideOutreachInstagram">
            <div id="slideFollowUpDots"></div>
            <span id="slideFollowUpLabel"></span>
            <div id="slideFirstContact"></div>
        </div>
    </div>
</div>

<!-- EXPORT PREVIEW MODAL -->
<div id="exportPreviewModal" class="modal-overlay">
    <div class="modal" style="max-width:640px;">
        <div class="modal-header">
            <h2 id="exportPreviewTitle">Export Preview</h2>
            <button class="btn-icon" onclick="app.closeExportPreview()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="max-height:60vh;overflow-y:auto;">
            <div id="exportPreviewSummary" style="margin-bottom:16px;"></div>
            <div id="exportPreviewTable" style="max-height:320px;overflow-y:auto;border:1px solid var(--card-border);border-radius:var(--radius-xs);"></div>
        </div>
        <div class="modal-footer" style="flex-direction:column;gap:12px;">
            <div id="exportPreviewActions" style="display:flex;gap:8px;width:100%;justify-content:flex-end;"></div>
        </div>
    </div>
</div>

<!-- IMPORT PREVIEW MODAL -->
<div id="importPreviewModal" class="modal-overlay">
    <div class="modal wide">
        <div class="modal-header">
            <h2 id="importPreviewTitle">Import CSV Preview</h2>
            <button class="btn-icon" id="importPreviewCloseBtn" onclick="app.closeImportPreview()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="importPreviewSummary" style="margin-bottom:16px;"></div>
            <div id="importProgressWrap" class="progress-container hidden" style="margin-bottom:16px;">
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" id="importProgressBar"></div>
                </div>
                <div class="progress-text">
                    <span id="importProgressText">Preparing import...</span>
                    <span id="importProgressCount"></span>
                </div>
            </div>
            <div id="importPreviewTable" style="max-height:52vh;overflow:auto;border:1px solid var(--card-border);border-radius:var(--radius-xs);"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" id="cancelImportBtn" onclick="app.closeImportPreview()">Cancel</button>
            <button class="btn btn-primary" id="startImportBtn" onclick="app.startImportCSV()"><i class="fas fa-upload"></i> Start Import</button>
        </div>
    </div>
</div>

<!-- GHL FULL EXPORT -->
<div id="ghlExportOverlay" class="ghl-overlay">
    <div class="ghl-panel">
        <div class="ghl-panel-header">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="btn-icon" onclick="app.closeGHLExport()" title="Close"><i class="fas fa-arrow-left"></i></button>
                <div>
                    <h2 style="margin:0;font-size:18px;font-weight:700;">Export to Free CRM</h2>
                    <div id="ghlSubtitle" style="font-size:12px;color:var(--text-secondary);margin-top:2px;"></div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span id="ghlConnectionBadge" style="font-size:11px;padding:4px 10px;border-radius:99px;font-weight:600;"></span>
                <button class="btn btn-sm" onclick="app.openGHLSettings()" style="font-size:12px;padding:5px 12px;"><i class="fas fa-cog"></i> Settings</button>
            </div>
        </div>

        <!-- GATE: do they have the Free CRM yet? -->
        <div id="ghlGateScreen" class="ghl-screen hidden">
            <div style="max-width:460px;margin:56px auto;text-align:center;">
                <div style="width:64px;height:64px;border-radius:16px;background:#eafaf0;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;">
                    <i class="fas fa-gift" style="font-size:26px;color:#16a34a;"></i>
                </div>
                <h2 style="margin:0 0 8px;font-size:22px;">Did you set up your Free CRM yet?</h2>
                <p style="color:var(--text-secondary);font-size:14px;line-height:1.6;margin:0 0 26px;">You push leads straight into your <strong>Free CRM</strong> (normally $97/mo &mdash; free for you). If you haven&rsquo;t claimed it yet, grab it first &mdash; it takes about a minute.</p>
                <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
                    <button class="btn btn-primary" onclick="app.ghlGateYes()" style="padding:13px 26px;font-size:15px;font-weight:700;"><i class="fas fa-check"></i> Yes, continue to setup</button>
                    <button class="btn btn-secondary" onclick="app.ghlGateNo()" style="padding:13px 26px;font-size:15px;font-weight:700;"><i class="fas fa-arrow-right"></i> No, get my Free CRM</button>
                </div>
            </div>
        </div>

        <!-- SETUP / PICK CONNECTION SCREEN -->
        <div id="ghlSetupScreen" class="ghl-screen">
            <div style="max-width:520px;margin:40px auto;">
                <div style="text-align:center;margin-bottom:28px;">
                    <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#FF6B35,#FF3B30);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                        <i class="fas fa-plug" style="font-size:24px;color:#fff;"></i>
                    </div>
                    <h2 style="margin:0 0 6px;font-size:22px;">Free CRM Connections</h2>
                    <p style="color:var(--text-secondary);font-size:13px;margin:0;">Select a connection or add a new one.</p>
                </div>

                <div id="ghlConnectionsList" style="margin-bottom:20px;"></div>

                <div id="ghlAddConnectionForm" class="hidden" style="background:var(--bg);border:1px solid var(--card-border);border-radius:12px;padding:20px;margin-bottom:16px;">
                    <div style="font-size:14px;font-weight:700;margin-bottom:14px;"><i class="fas fa-plus-circle" style="color:var(--accent);margin-right:6px;"></i>New Connection</div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Connection Name</label>
                        <input type="text" id="ghlSetupName" placeholder="e.g. My Agency, Client XYZ..." style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:14px;font-family:inherit;background:#fff;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:12px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">API Key</label>
                        <input type="password" id="ghlSetupKey" placeholder="eyJhbGciOi..." style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:14px;font-family:inherit;background:#fff;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Location ID</label>
                        <input type="text" id="ghlSetupLocation" placeholder="abc123..." style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:14px;font-family:inherit;background:#fff;outline:none;">
                    </div>
                    <details style="margin-bottom:14px;">
                        <summary style="font-size:12px;color:var(--accent);cursor:pointer;font-weight:600;"><i class="fas fa-shield-alt" style="margin-right:4px;"></i>Required API Scopes & Setup Help</summary>
                        <div style="margin-top:8px;padding:12px;background:#fff;border:1px solid var(--card-border);border-radius:8px;">
                            <div style="font-size:11.5px;color:var(--text-secondary);line-height:1.75;">
                                <strong>1.</strong> In your Free CRM: <strong>Settings → Private Integrations</strong><br>
                                <strong>2.</strong> Click <strong>Create new integration</strong> &mdash; enter a name &amp; description<br>
                                <strong>3.</strong> Select these scopes:
                                <div style="display:flex;flex-wrap:wrap;gap:4px;margin:6px 0 8px;">
                                    <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;background:#DCFCE7;color:#166534;">contacts.readonly</span>
                                    <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;background:#DCFCE7;color:#166534;">contacts.write</span>
                                    <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;background:#DBEAFE;color:#1E40AF;">locations/tags.readonly</span>
                                    <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;background:#DBEAFE;color:#1E40AF;">locations/tags.write</span>
                                    <span style="padding:3px 8px;border-radius:5px;font-size:10px;font-weight:600;background:#FEF3C7;color:#92400E;">workflows.readonly</span>
                                </div>
                                <strong>4.</strong> <strong>Copy the API key</strong> and paste it in the API Key field above<br>
                                <strong>5.</strong> Your <strong>Location ID</strong> is in your URL: <code style="background:var(--card-border);padding:1px 4px;border-radius:3px;font-size:10px;">app.allinonemarketing.com/v2/location/<strong>ID</strong>/...</code> &mdash; paste it above<br>
                                <strong>6.</strong> Click <strong>Connect</strong>
                            </div>
                        </div>
                    </details>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-primary" onclick="app.connectGHL()" style="flex:1;padding:10px;font-size:14px;font-weight:600;"><i class="fas fa-bolt"></i> Connect</button>
                        <button class="btn btn-secondary" onclick="document.getElementById('ghlAddConnectionForm').classList.add('hidden')" style="padding:10px 16px;font-size:14px;">Cancel</button>
                    </div>
                </div>

                <button class="btn btn-secondary" onclick="document.getElementById('ghlAddConnectionForm').classList.remove('hidden')" style="width:100%;padding:12px;font-size:14px;border:2px dashed var(--card-border);background:transparent;"><i class="fas fa-plus"></i> Add New Connection</button>
            </div>
        </div>

        <!-- MAIN EDITOR SCREEN -->
        <div id="ghlEditorScreen" class="ghl-screen hidden">
            <div class="ghl-toolbar">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <select id="ghlConnectionPicker" onchange="app.switchGHLConnection()" style="padding:7px 12px;border:1px solid var(--accent);border-radius:6px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;font-weight:600;color:var(--accent);max-width:180px;">
                    </select>
                    <input type="text" id="ghlSearchInput" placeholder="Search leads..." oninput="app.filterGHLLeads()" style="padding:7px 12px;border:1px solid var(--card-border);border-radius:6px;font-size:13px;font-family:inherit;width:200px;background:var(--bg);outline:none;">
                    <select id="ghlFilterSelect" onchange="app.filterGHLLeads()" style="padding:7px 12px;border:1px solid var(--card-border);border-radius:6px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;">
                        <option value="all">All Leads</option>
                        <option value="has_email">Has Email</option>
                        <option value="has_phone">Has Phone</option>
                        <option value="has_both">Email & Phone</option>
                    </select>
                    <select id="ghlImportFilter" onchange="app.filterGHLLeads()" style="padding:7px 12px;border:1px solid var(--card-border);border-radius:6px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;">
                        <option value="any">Any Import Status</option>
                        <option value="not_imported">Not Previously Imported</option>
                        <option value="previously_imported">Previously Imported</option>
                    </select>
                    <span id="ghlLeadCount" style="font-size:12px;color:var(--text-secondary);font-weight:600;"></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button class="btn btn-sm" onclick="app.ghlSelectAll()" style="font-size:12px;"><i class="fas fa-check-double"></i> Select All</button>
                    <button class="btn btn-sm" onclick="app.ghlDeselectAll()" style="font-size:12px;"><i class="fas fa-times"></i> Deselect All</button>
                </div>
            </div>

            <div class="ghl-spreadsheet-wrap">
                <table class="ghl-spreadsheet" id="ghlSpreadsheet">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="ghlSelectAllCb" onchange="app.ghlToggleSelectAll(this.checked)"></th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Company</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>State</th>
                            <th>Website</th>
                            <th>Socials</th>
                        </tr>
                    </thead>
                    <tbody id="ghlSpreadsheetBody"></tbody>
                </table>
            </div>

            <div class="ghl-bottom-bar">
                <div class="ghl-config-section">
                    <div class="ghl-config-group">
                        <label><i class="fas fa-tags"></i> Tags</label>
                        <div id="ghlTagsArea" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
                            <div id="ghlSelectedTags" style="display:flex;flex-wrap:wrap;gap:4px;"></div>
                            <div style="position:relative;">
                                <input type="text" id="ghlTagInput" placeholder="Add tag..." oninput="app.filterGHLTags()" onfocus="app.showGHLTagDropdown()" style="padding:5px 10px;border:1px solid var(--card-border);border-radius:6px;font-size:12px;width:140px;background:var(--bg);outline:none;">
                                <div id="ghlTagDropdown" class="ghl-dropdown hidden"></div>
                            </div>
                        </div>
                    </div>
                    <div class="ghl-config-group">
                        <label><i class="fas fa-project-diagram"></i> Workflow</label>
                        <select id="ghlWorkflowSelect" style="padding:6px 10px;border:1px solid var(--card-border);border-radius:6px;font-size:12px;background:var(--bg);outline:none;min-width:200px;">
                            <option value="">No workflow</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div id="ghlSummaryText" style="font-size:13px;color:var(--text-secondary);"></div>
                    <button class="btn btn-sm" onclick="app.openGHLDripConfig()" style="font-size:12px;" title="Drip / Schedule"><i class="fas fa-clock"></i> Drip</button>
                    <button class="btn btn-sm" onclick="app.showGHLImportLogs()" style="font-size:12px;" title="Import History"><i class="fas fa-history"></i> Logs</button>
                    <button class="btn btn-primary" id="ghlImportBtn" onclick="app.startGHLImport()" style="padding:10px 24px;font-size:14px;font-weight:700;white-space:nowrap;"><i class="fas fa-paper-plane"></i> Import to Free CRM</button>
                </div>
            </div>
        </div>

        <!-- IMPORTING SCREEN -->
        <div id="ghlImportingScreen" class="ghl-screen hidden">
            <div style="max-width:520px;margin:40px auto;text-align:center;">
                <div id="ghlImportSpinner" style="margin-bottom:20px;">
                    <div style="width:80px;height:80px;border-radius:50%;border:4px solid var(--card-border);border-top-color:var(--accent);animation:spin 0.8s linear infinite;margin:0 auto;"></div>
                </div>
                <h2 id="ghlImportTitle" style="margin:0 0 6px;font-size:20px;">Importing to your Free CRM...</h2>
                <p id="ghlImportSubtitle" style="color:var(--text-secondary);margin:0 0 24px;font-size:13px;">Please wait, this may take a few minutes for large lists.</p>
                <div style="background:var(--bg);border-radius:12px;padding:16px;border:1px solid var(--card-border);">
                    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:8px;">
                        <span id="ghlImportProgressLabel">0 of 0</span>
                        <span id="ghlImportPct">0%</span>
                    </div>
                    <div style="height:8px;background:var(--card-border);border-radius:99px;overflow:hidden;">
                        <div id="ghlImportBar" style="height:100%;background:var(--accent);border-radius:99px;width:0%;transition:width 0.3s;"></div>
                    </div>
                    <div style="display:flex;gap:20px;margin-top:14px;justify-content:center;">
                        <div style="text-align:center;"><div id="ghlStatNew" style="font-size:20px;font-weight:700;color:var(--green);">0</div><div style="font-size:11px;color:var(--text-tertiary);">New</div></div>
                        <div style="text-align:center;"><div id="ghlStatUpdated" style="font-size:20px;font-weight:700;color:var(--blue);">0</div><div style="font-size:11px;color:var(--text-tertiary);">Updated</div></div>
                        <div style="text-align:center;"><div id="ghlStatFailed" style="font-size:20px;font-weight:700;color:var(--red);">0</div><div style="font-size:11px;color:var(--text-tertiary);">Failed</div></div>
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:center;margin-top:16px;">
                    <button class="btn btn-sm" id="ghlPauseBtn" onclick="app.pauseCurrentImport()" style="font-size:12px;"><i class="fas fa-pause"></i> Pause</button>
                    <button class="btn btn-sm" id="ghlCancelImportBtn" onclick="app.cancelCurrentImport()" style="font-size:12px;color:var(--red);"><i class="fas fa-stop"></i> Cancel</button>
                </div>
            </div>
        </div>

        <!-- DONE SCREEN -->
        <div id="ghlDoneScreen" class="ghl-screen hidden">
            <canvas id="confettiCanvas" style="position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:1;"></canvas>
            <div style="max-width:520px;margin:40px auto;text-align:center;position:relative;z-index:2;">
                <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,#34C759,#30D158);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                    <i class="fas fa-check" style="font-size:36px;color:#fff;"></i>
                </div>
                <h2 style="margin:0 0 8px;font-size:24px;">Import Complete!</h2>
                <p id="ghlDoneSubtitle" style="color:var(--text-secondary);font-size:14px;margin:0 0 28px;"></p>
                <div style="background:var(--bg);border-radius:12px;padding:20px;border:1px solid var(--card-border);text-align:left;margin-bottom:24px;">
                    <div id="ghlDoneSummary" style="font-size:14px;line-height:1.8;"></div>
                </div>
                <div id="ghlDoneErrors" class="hidden" style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:12px;padding:16px;text-align:left;margin-bottom:24px;max-height:150px;overflow-y:auto;">
                    <div style="font-size:13px;font-weight:600;color:var(--red);margin-bottom:8px;"><i class="fas fa-exclamation-triangle"></i> Some contacts had errors:</div>
                    <div id="ghlDoneErrorList" style="font-size:12px;color:#666;"></div>
                </div>
                <button class="btn btn-primary" onclick="app.closeGHLExport()" style="padding:12px 32px;font-size:15px;font-weight:600;"><i class="fas fa-check"></i> Done</button>
            </div>
        </div>

        <!-- SETTINGS SLIDE -->
        <div id="ghlSettingsSlide" class="ghl-settings-slide hidden">
            <div style="padding:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                    <h3 style="margin:0;font-size:16px;"><i class="fas fa-cog" style="color:var(--accent);margin-right:6px;"></i>Manage Connections</h3>
                    <button class="btn-icon" onclick="app.closeGHLSettings()"><i class="fas fa-times"></i></button>
                </div>
                <div id="ghlSettingsConnectionsList" style="margin-bottom:16px;"></div>
                <button class="btn btn-secondary" onclick="app.showGHLScreen('ghlSetupScreen');app.closeGHLSettings();" style="width:100%;padding:10px;font-size:13px;border:2px dashed var(--card-border);background:transparent;"><i class="fas fa-plus"></i> Add Connection</button>
            </div>
        </div>

        <!-- DRIP CONFIG SLIDE -->
        <div id="ghlDripSlide" class="ghl-settings-slide hidden">
            <div style="padding:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                    <h3 style="margin:0;font-size:16px;"><i class="fas fa-clock" style="color:var(--accent);margin-right:6px;"></i>Drip Schedule</h3>
                    <button class="btn-icon" onclick="document.getElementById('ghlDripSlide').classList.add('hidden')"><i class="fas fa-times"></i></button>
                </div>
                <p style="font-size:12px;color:var(--text-secondary);margin:0 0 16px;">Send contacts in batches over time instead of all at once. Great for large lists.</p>
                <div style="margin-bottom:16px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer;">
                        <input type="checkbox" id="ghlDripEnabled" onchange="app.toggleDripFields()" style="accent-color:var(--accent);">
                        Enable drip scheduling
                    </label>
                </div>
                <div id="ghlDripFields" class="hidden">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Batch Size</label>
                        <input type="number" id="ghlDripBatch" value="50" min="1" max="500" style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;">
                        <div style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Contacts per batch</div>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Interval Between Batches</label>
                        <select id="ghlDripInterval" style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;">
                            <option value="15">Every 15 minutes</option>
                            <option value="30">Every 30 minutes</option>
                            <option value="60" selected>Every 1 hour</option>
                            <option value="120">Every 2 hours</option>
                            <option value="240">Every 4 hours</option>
                            <option value="480">Every 8 hours</option>
                            <option value="1440">Every 24 hours</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Timezone</label>
                        <select id="ghlDripTimezone" style="width:100%;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:13px;font-family:inherit;background:var(--bg);outline:none;">
                            <option value="America/New_York">Eastern (ET)</option>
                            <option value="America/Chicago">Central (CT)</option>
                            <option value="America/Denver">Mountain (MT)</option>
                            <option value="America/Los_Angeles">Pacific (PT)</option>
                            <option value="America/Anchorage">Alaska (AKT)</option>
                            <option value="Pacific/Honolulu">Hawaii (HT)</option>
                            <option value="Europe/London">London (GMT)</option>
                            <option value="Europe/Paris">Central Europe (CET)</option>
                            <option value="Asia/Tokyo">Tokyo (JST)</option>
                            <option value="Australia/Sydney">Sydney (AEST)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;margin-bottom:4px;display:block;">Send Time (optional — first batch starts at this time)</label>
                        <div style="display:flex;gap:8px;">
                            <select id="ghlDripHour" style="flex:1;padding:10px 14px;border:1px solid var(--card-border);border-radius:8px;font-size:13px;background:var(--bg);outline:none;">
                                <option value="">Any time</option>
                                <option value="6">6:00 AM</option>
                                <option value="7">7:00 AM</option>
                                <option value="8">8:00 AM</option>
                                <option value="9" selected>9:00 AM</option>
                                <option value="10">10:00 AM</option>
                                <option value="11">11:00 AM</option>
                                <option value="12">12:00 PM</option>
                                <option value="13">1:00 PM</option>
                                <option value="14">2:00 PM</option>
                                <option value="15">3:00 PM</option>
                                <option value="16">4:00 PM</option>
                                <option value="17">5:00 PM</option>
                            </select>
                        </div>
                        <div style="font-size:11px;color:var(--text-tertiary);margin-top:4px;">Emails sent at proper local time for your recipients</div>
                    </div>
                </div>
                <button class="btn btn-primary" onclick="document.getElementById('ghlDripSlide').classList.add('hidden')" style="width:100%;padding:10px;font-size:14px;margin-top:8px;"><i class="fas fa-check"></i> Save Drip Settings</button>
            </div>
        </div>

        <!-- IMPORT LOGS SLIDE -->
        <div id="ghlLogsSlide" class="ghl-settings-slide hidden" style="width:520px;">
            <div style="padding:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
                    <h3 style="margin:0;font-size:16px;"><i class="fas fa-history" style="color:var(--accent);margin-right:6px;"></i>Import History</h3>
                    <button class="btn-icon" onclick="document.getElementById('ghlLogsSlide').classList.add('hidden')"><i class="fas fa-times"></i></button>
                </div>
                <div id="ghlLogsContent" style="font-size:13px;">
                    <div style="text-align:center;padding:30px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT LOGS MODAL -->
<div id="importLogsModal" class="modal-overlay">
    <div style="background:var(--card-solid);border-radius:16px;width:90%;max-width:700px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,0.25);">
        <div style="padding:16px 20px;border-bottom:1px solid var(--card-border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:10px;">
                <i class="fas fa-history" style="color:var(--accent);font-size:18px;"></i>
                <h3 style="margin:0;font-size:16px;">Free CRM Import History</h3>
            </div>
            <button class="btn-icon" onclick="document.getElementById('importLogsModal').classList.remove('active')"><i class="fas fa-times"></i></button>
        </div>
        <div id="importLogsModalContent" style="flex:1;overflow-y:auto;padding:20px;">
            <div style="text-align:center;padding:30px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<!-- TOAST -->
<div id="toast" class="toast"></div>

<script>
const FOLDER_COLORS = ['folder-blue','folder-purple','folder-green','folder-orange','folder-pink','folder-teal'];
const FOLDER_ICONS = ['fa-briefcase','fa-heart-pulse','fa-user-doctor','fa-building','fa-store','fa-star'];

const SOCIAL_PLATFORMS = [
    { name: 'Facebook', icon: 'fa-brands fa-facebook', patterns: ['facebook.com','fb.com','fb.me'] },
    { name: 'Instagram', icon: 'fa-brands fa-instagram', patterns: ['instagram.com','instagr.am'] },
    { name: 'Twitter', icon: 'fa-brands fa-x-twitter', patterns: ['twitter.com','x.com'] },
    { name: 'LinkedIn', icon: 'fa-brands fa-linkedin', patterns: ['linkedin.com'] },
    { name: 'YouTube', icon: 'fa-brands fa-youtube', patterns: ['youtube.com','youtu.be'] },
    { name: 'TikTok', icon: 'fa-brands fa-tiktok', patterns: ['tiktok.com'] }
];

class LeadListsApp {
    constructor() {
        this.lists = [];
        this.currentList = null;
        this.currentLeads = [];
        this.selectedLeads = new Set();
        this.currentPage = 1;
        this.totalPages = 1;
        this.totalLeads = 0;
        this.perPage = 50;
        this.searchQuery = '';

        this.editingListId = null;
        this.credits = <?php echo $userCredits; ?>;
        this.selectedCountry = 'US';
        this.statesCache = {};
        this.states = [];
        this.cities = [];
        this.selectedStates = new Set();
        this.selectedCities = new Set();
        this.citySortOrder = 'name';
        this.searchedCitiesForList = new Set();
        this.cityFilter = 'all';
        this.scraping = false;
        this.enrichmentPollId = null;
        this.currentLeadDetail = null;
        this.searchDebounce = null;
        this.hasFilter = '';
        this.exportType = null;
        this.exportLeadsData = null;
        this.mapsApiUrl = 'maps_proxy.php?type=search';
        this.slideMap = null;
        this.listMap = null;
        this.listMapMarkers = [];
        this.listMapDrawnItems = null;
        this.listMapVisible = true;
        this.allMapLeads = [];

        this.emailTemplate = JSON.parse(localStorage.getItem('emailTemplate') || 'null') || { my_name: '', my_company: '', subject: '', body: '' };

        this.init();
    }

    async init() {
        await this.loadLists();
    }

    async api(action, params = {}, method = 'GET') {
        let url = `leadlists.php?action=${action}`;
        const opts = { headers: {}, cache: 'no-store' };

        if (method === 'GET') {
            Object.entries(params).forEach(([k, v]) => url += `&${k}=${encodeURIComponent(v)}`);
        } else {
            opts.method = 'POST';
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(params);
        }

        try {
            const res = await fetch(url, opts);
            if (!res.ok) return { success: false, error: 'Server error ' + res.status };
            return await res.json();
        } catch (e) {
            return { success: false, error: e.message };
        }
    }

    toast(msg) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 2500);
    }

    showUpgradePrompt(needed) {
        const have = document.getElementById('upgradeHave');
        if (have) have.textContent = this.credits;
        document.getElementById('upgradeModal').style.display = 'flex';
    }
    closeUpgradeModal() {
        document.getElementById('upgradeModal').style.display = 'none';
    }
    gotoPenny() {
        // Top-plan users are promoted to the 1¢ page instead of an upgrade.
        this.closeUpgradeModal();
        (window.top || window).location.href = 'dashboard.php?section=penny';
    }
    async upgradeTo(priceId, btnEl) {
        if (!priceId) { this.toast('This plan is not available yet.'); return; }
        if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Redirecting…'; }
        try {
            const res = await fetch('create_subscription_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ price_id: priceId })
            });
            const data = await res.json();
            // This page runs inside the dashboard iframe; Stripe can't be framed,
            // so navigate the TOP window out to Checkout.
            if (data.url) { (window.top || window).location.href = data.url; return; }
            this.toast(data.error || 'Could not start checkout.');
        } catch (e) {
            this.toast('Could not start checkout.');
        }
        if (btnEl) { btnEl.disabled = false; btnEl.textContent = 'Upgrade'; }
    }

    async shareForCredits() {
        const url = window.location.origin + '/dashboard?section=lead_lists';
        const shareData = { title: '<?php echo APP_NAME; ?>', text: 'Check out this lead generation tool!', url };

        try {
            if (navigator.share) {
                await navigator.share(shareData);
            } else {
                await navigator.clipboard.writeText(url);
                this.toast('Link copied to clipboard!');
            }
        } catch (e) {
            await navigator.clipboard.writeText(url);
            this.toast('Link copied to clipboard!');
        }

        const res = await this.api('claimShareCredits', {}, 'POST');
        if (res.success) {
            this.credits = res.credits;
            this.updateCreditsDisplay();
            document.getElementById('shareForCreditsWrap').style.display = 'none';
            document.getElementById('upgradeHave').textContent = this.credits;
            this.toast('+5 free credits added!');
            if (window.parent !== window) {
                window.parent.postMessage({ type: 'creditUpdate', credits: this.credits }, '*');
            }
        } else if (res.error === 'Already claimed') {
            document.getElementById('shareForCreditsWrap').style.display = 'none';
            this.toast('You already claimed your share bonus');
        }
    }

    // EMAIL TEMPLATE

    openEmailTemplateModal() {
        document.getElementById('etMyName').value = this.emailTemplate.my_name || '';
        document.getElementById('etMyCompany').value = this.emailTemplate.my_company || '';
        document.getElementById('etSubject').value = this.emailTemplate.subject || '';
        document.getElementById('etBody').value = this.emailTemplate.body || '';
        document.getElementById('emailTemplateModal').classList.add('active');
    }

    insertTemplateVar(target, variable) {
        const field = target === 'subject' ? document.getElementById('etSubject') : document.getElementById('etBody');
        const start = field.selectionStart;
        const end = field.selectionEnd;
        const text = field.value;
        field.value = text.substring(0, start) + variable + text.substring(end);
        field.focus();
        field.setSelectionRange(start + variable.length, start + variable.length);
    }

    saveEmailTemplate() {
        this.emailTemplate = {
            my_name: document.getElementById('etMyName').value.trim(),
            my_company: document.getElementById('etMyCompany').value.trim(),
            subject: document.getElementById('etSubject').value.trim(),
            body: document.getElementById('etBody').value.trim()
        };
        localStorage.setItem('emailTemplate', JSON.stringify(this.emailTemplate));
        document.getElementById('emailTemplateModal').classList.remove('active');
        this.toast('Email template saved!');
    }

    clearEmailTemplate() {
        if (!confirm('Clear your email template?')) return;
        this.emailTemplate = { my_name: '', my_company: '', subject: '', body: '' };
        localStorage.removeItem('emailTemplate');
        document.getElementById('etMyName').value = '';
        document.getElementById('etMyCompany').value = '';
        document.getElementById('etSubject').value = '';
        document.getElementById('etBody').value = '';
        this.toast('Template cleared');
    }

    buildMailtoLink(email, lead) {
        const t = this.emailTemplate;
        if (!t.subject && !t.body) return `mailto:${email}`;

        const vars = {
            '{business_name}': lead?.business_name || '',
            '{city}': lead?.city || '',
            '{state}': lead?.state || '',
            '{my_name}': t.my_name || '',
            '{my_company}': t.my_company || ''
        };

        let subject = t.subject || '';
        let body = t.body || '';
        for (const [k, v] of Object.entries(vars)) {
            subject = subject.split(k).join(v);
            body = body.split(k).join(v);
        }

        let url = `mailto:${email}`;
        const parts = [];
        if (subject) parts.push(`subject=${encodeURIComponent(subject)}`);
        if (body) parts.push(`body=${encodeURIComponent(body)}`);
        if (parts.length) url += '?' + parts.join('&');
        return url;
    }

    sendTemplateEmail(email, leadId) {
        const lead = this.currentLeads?.find(l => l.id == leadId) || this.allMapLeads?.find(l => l.id == leadId) || {};
        const url = this.buildMailtoLink(email, lead);
        window.open(url, '_blank');
    }

    showEmailPopover(btnEl, leadId) {
        if (this._emailPopoverBtn === btnEl && document.getElementById('emailPopover')) return;
        this.dismissEmailPopover();
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;
        const emails = lead.emails || [];
        if (emails.length === 0) return;

        this._emailPopoverBtn = btnEl;
        const pop = document.createElement('div');
        pop.className = 'email-popover';
        pop.id = 'emailPopover';
        pop.onclick = (e) => e.stopPropagation();
        pop.innerHTML = emails.map(e =>
            `<div class="email-popover-item">
                <span class="ep-email" title="${this.esc(e)}">${this.esc(e)}</span>
                <div class="ep-actions">
                    <button class="ep-btn" title="Copy" onclick="navigator.clipboard.writeText('${this.esc(e)}');app.toast('Copied');"><i class="fas fa-copy"></i></button>
                    <button class="ep-btn" title="Send email" onclick="app.sendTemplateEmail('${this.esc(e)}', ${leadId});app.dismissEmailPopover();"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>`
        ).join('');

        pop.onmouseenter = () => clearTimeout(this._emailPopoverTimer);
        pop.onmouseleave = () => { this._emailPopoverTimer = setTimeout(() => this.dismissEmailPopover(), 200); };
        btnEl.onmouseleave = () => { this._emailPopoverTimer = setTimeout(() => this.dismissEmailPopover(), 200); };

        document.body.appendChild(pop);
        const rect = btnEl.getBoundingClientRect();
        pop.style.top = (rect.bottom + 6 + window.scrollY) + 'px';
        pop.style.left = Math.max(8, Math.min(rect.left + window.scrollX - 80, window.innerWidth - pop.offsetWidth - 8)) + 'px';
    }

    dismissEmailPopover() {
        const pop = document.getElementById('emailPopover');
        if (pop) pop.remove();
        this._emailPopoverBtn = null;
        clearTimeout(this._emailPopoverTimer);
    }

    showNotePopover(btnEl, leadId) {
        this.dismissNotePopover();
        this.dismissEmailPopover();
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;

        const pop = document.createElement('div');
        pop.className = 'note-popover';
        pop.id = 'notePopover';
        pop.onclick = (e) => e.stopPropagation();
        pop.innerHTML = `
            <div class="note-popover-header">
                <span><i class="fas fa-sticky-note" style="margin-right:6px;color:var(--orange);"></i>Notes</span>
                <button onclick="app.dismissNotePopover()" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);font-size:14px;"><i class="fas fa-times"></i></button>
            </div>
            <textarea id="notePopoverText" placeholder="Add a note...">${this.esc(lead.notes || '')}</textarea>
            <div class="note-popover-footer">
                <button class="btn btn-sm btn-secondary" onclick="app.dismissNotePopover()">Cancel</button>
                <button class="btn btn-sm btn-primary" onclick="app.saveNotePopover(${leadId})"><i class="fas fa-save"></i> Save</button>
            </div>
        `;
        document.body.appendChild(pop);

        const rect = btnEl.getBoundingClientRect();
        const popH = pop.offsetHeight;
        const spaceBelow = window.innerHeight - rect.bottom;
        if (spaceBelow < popH + 10) {
            pop.style.top = (rect.top + window.scrollY - popH - 6) + 'px';
        } else {
            pop.style.top = (rect.bottom + 6 + window.scrollY) + 'px';
        }
        pop.style.left = Math.max(8, Math.min(rect.right + window.scrollX - pop.offsetWidth, window.innerWidth - pop.offsetWidth - 8)) + 'px';

        const ta = document.getElementById('notePopoverText');
        ta.focus();
        ta.setSelectionRange(ta.value.length, ta.value.length);

        setTimeout(() => {
            this._notePopoverDismiss = (e) => {
                if (!pop.contains(e.target)) this.dismissNotePopover();
            };
            document.addEventListener('click', this._notePopoverDismiss);
        }, 0);
    }

    async saveNotePopover(leadId) {
        const notes = document.getElementById('notePopoverText')?.value || '';
        await this.api('updateLead', { id: leadId, notes }, 'POST');
        this.toast('Note saved');
        this.dismissNotePopover();
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (lead) lead.notes = notes;
        this.renderLeads();
        if (this.currentList) {
            const data = await this.api('getListDetail', { id: this.currentList.id });
            if (data.success) { this.currentList = data.list; this.renderStats(); }
        }
    }

    dismissNotePopover() {
        const pop = document.getElementById('notePopover');
        if (pop) pop.remove();
        if (this._notePopoverDismiss) {
            document.removeEventListener('click', this._notePopoverDismiss);
            this._notePopoverDismiss = null;
        }
    }

    // LIST MANAGEMENT

    async loadLists() {
        const data = await this.api('getLists');
        if (data.success) {
            this.lists = data.lists;
            this.renderLists();
        }
    }

    renderLists() {
        const grid = document.getElementById('folderGrid');
        const empty = document.getElementById('emptyState');

        const newListBtn = document.querySelector('.page-header .btn-primary');
        if (this.lists.length === 0) {
            grid.classList.add('hidden');
            empty.classList.remove('hidden');
            if (newListBtn) newListBtn.classList.add('pulse-cue');
            return;
        }

        if (newListBtn) newListBtn.classList.remove('pulse-cue');
        empty.classList.add('hidden');
        grid.classList.remove('hidden');

        grid.innerHTML = this.lists.map((list, i) => {
            const color = FOLDER_COLORS[i % FOLDER_COLORS.length];
            const icon = FOLDER_ICONS[i % FOLDER_ICONS.length];
            const total = parseInt(list.lead_count) || 0;
            const visited = parseInt(list.visited_count) || 0;
            const reachedOut = parseInt(list.reached_out_count) || 0;
            const sNew = parseInt(list.stage_new) || 0;
            const sContacted = parseInt(list.stage_contacted) || 0;
            const engaged = parseInt(list.engaged_count) || 0;
            const clients = parseInt(list.client_count) || 0;
            const sNoResp = parseInt(list.stage_no_response) || 0;
            const cities = parseInt(list.cities_searched) || 0;

            return `
                <div class="folder-card" onclick="app.openList(${list.id})">
                    <div class="folder-actions">
                        <button class="btn-icon" onclick="event.stopPropagation();app.openShareModal(${list.id})" title="Share"><i class="fas fa-share-alt" style="font-size:12px;color:var(--accent);"></i></button>
                        <button class="btn-icon" onclick="event.stopPropagation();app.openEditModal(${list.id})" title="Edit"><i class="fas fa-pencil" style="font-size:12px;"></i></button>
                        <button class="btn-icon" onclick="event.stopPropagation();app.deleteList(${list.id})" title="Delete"><i class="fas fa-trash" style="font-size:12px;color:var(--red);"></i></button>
                    </div>
                    ${list.is_public == 1 ? '<div style="position:absolute;bottom:10px;right:12px;font-size:10px;color:var(--accent);font-weight:600;display:flex;align-items:center;gap:6px;"><i class="fas fa-globe"></i> Public' + (parseInt(list.claim_count) > 0 ? ' <span style="background:var(--accent);color:#fff;padding:1px 6px;border-radius:8px;font-size:9px;">' + list.claim_count + ' claimed</span>' : '') + '</div>' : ''}
                    <div class="folder-icon ${color}"><i class="fas ${icon}"></i></div>
                    <div class="name">${this.esc(list.name)}</div>
                    <div class="desc">${list.description ? this.esc(list.description) : 'No description'}</div>
                    <div class="meta">
                        <span><i class="fas fa-users"></i> ${total.toLocaleString()} leads</span>
                        <span><i class="fas fa-map-marker-alt"></i> ${cities} cities</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:3px;margin-top:12px;padding:8px 0 2px;border-top:1px solid var(--card-border);">
                        <div style="flex:1;text-align:center;">
                            <div style="font-size:14px;font-weight:700;color:var(--text-secondary);">${sNew}</div>
                            <div style="font-size:9px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.3px;">New</div>
                        </div>
                        <i class="fas fa-chevron-right" style="font-size:7px;color:var(--text-tertiary);"></i>
                        <div style="flex:1;text-align:center;">
                            <div style="font-size:14px;font-weight:700;color:var(--accent);">${sContacted}</div>
                            <div style="font-size:9px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.3px;">Contacted</div>
                        </div>
                        <i class="fas fa-chevron-right" style="font-size:7px;color:var(--text-tertiary);"></i>
                        <div style="flex:1;text-align:center;">
                            <div style="font-size:14px;font-weight:700;color:var(--orange);">${engaged}</div>
                            <div style="font-size:9px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.3px;">Engaged</div>
                        </div>
                        <i class="fas fa-chevron-right" style="font-size:7px;color:var(--text-tertiary);"></i>
                        <div style="flex:1;text-align:center;">
                            <div style="font-size:14px;font-weight:700;color:var(--green);">${clients}</div>
                            <div style="font-size:9px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.3px;">Client</div>
                        </div>
                        <i class="fas fa-chevron-right" style="font-size:7px;color:var(--text-tertiary);"></i>
                        <div style="flex:1;text-align:center;">
                            <div style="font-size:14px;font-weight:700;color:var(--red);">${sNoResp}</div>
                            <div style="font-size:9px;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:0.3px;">No Resp</div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    openCreateModal() {
        // Out of credits — nudge to upgrade instead of starting a new list.
        if (this.credits < 1) { this.showUpgradePrompt(1); return; }
        this.editingListId = null;
        document.getElementById('createModalTitle').textContent = 'New Lead List';
        document.getElementById('createListBtn').textContent = 'Create';
        document.getElementById('listName').value = '';
        document.getElementById('listDesc').value = '';
        document.getElementById('createModal').classList.add('active');
        document.getElementById('listName').focus();
    }

    openEditModal(id) {
        const list = this.lists.find(l => l.id == id);
        if (!list) return;
        this.editingListId = id;
        document.getElementById('createModalTitle').textContent = 'Edit Lead List';
        document.getElementById('createListBtn').textContent = 'Save';
        document.getElementById('listName').value = list.name;
        document.getElementById('listDesc').value = list.description || '';
        document.getElementById('createModal').classList.add('active');
        document.getElementById('listName').focus();
    }

    openShareModal(listId) {
        const id = listId || (this.currentList ? this.currentList.id : null);
        if (!id) return;
        const list = this.lists.find(l => l.id == id) || this.currentList;
        if (!list) return;
        this.sharingListId = id;
        const isPublic = list.is_public == 1;
        document.getElementById('shareToggle').checked = isPublic;
        const linkSection = document.getElementById('shareLinkSection');
        if (isPublic && list.public_token) {
            linkSection.classList.remove('hidden');
            document.getElementById('shareLinkInput').value = `<?php echo APP_URL; ?>/public_list.php?token=${list.public_token}`;
            this.loadShareClaims(id);
        } else {
            linkSection.classList.add('hidden');
        }
        document.getElementById('shareModal').classList.add('active');
    }

    async loadShareClaims(listId) {
        const countEl = document.getElementById('shareClaimCount');
        const listEl = document.getElementById('shareClaimsList');
        countEl.textContent = '...';
        listEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text-tertiary);font-size:12px;"><i class="fas fa-spinner fa-spin"></i></div>';
        const data = await this.api('getListClaims', { list_id: listId });
        if (!data.success || !data.claims) {
            countEl.textContent = '0';
            listEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text-tertiary);font-size:12px;">No signups yet</div>';
            return;
        }
        countEl.textContent = data.claims.length;
        if (data.claims.length === 0) {
            listEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--text-tertiary);font-size:12px;">No signups yet — share your link to start capturing leads!</div>';
            return;
        }
        listEl.innerHTML = data.claims.map(c => {
            const d = new Date(c.created_at);
            const ago = this.timeAgo(d);
            const typeIcon = c.claim_type === 'signup' ? '<i class="fas fa-user-plus" style="color:var(--green);font-size:10px;"></i>' : '<i class="fas fa-sign-in-alt" style="color:var(--accent);font-size:10px;"></i>';
            const typeLbl = c.claim_type === 'signup' ? 'New signup' : 'Existing user';
            return `<div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;margin-bottom:4px;background:var(--bg);border:1px solid var(--card-border);">
                <div style="width:32px;height:32px;border-radius:50%;background:var(--accent-light);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:var(--accent);flex-shrink:0;">${(c.claimed_user_name || '?')[0].toUpperCase()}</div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.esc(c.claimed_user_name || 'Unknown')}</div>
                    <div style="font-size:11px;color:var(--text-tertiary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.esc(c.claimed_user_email || '')}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:10px;display:flex;align-items:center;gap:3px;justify-content:flex-end;">${typeIcon} ${typeLbl}</div>
                    <div style="font-size:10px;color:var(--text-tertiary);margin-top:1px;">${ago}</div>
                </div>
            </div>`;
        }).join('');
    }

    timeAgo(date) {
        const s = Math.floor((Date.now() - date.getTime()) / 1000);
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s/60) + 'm ago';
        if (s < 86400) return Math.floor(s/3600) + 'h ago';
        if (s < 2592000) return Math.floor(s/86400) + 'd ago';
        return date.toLocaleDateString();
    }

    async toggleSharePublic(checked) {
        const res = await this.api('togglePublic', { id: this.sharingListId, is_public: checked ? 1 : 0 }, 'POST');
        if (!res.success) { this.toast('Error updating share settings'); return; }
        const linkSection = document.getElementById('shareLinkSection');
        if (checked && res.public_token) {
            linkSection.classList.remove('hidden');
            const url = `<?php echo APP_URL; ?>/public_list.php?token=${res.public_token}`;
            document.getElementById('shareLinkInput').value = url;
            this.loadShareClaims(this.sharingListId);
        } else {
            linkSection.classList.add('hidden');
        }
        const list = this.lists.find(l => l.id == this.sharingListId);
        if (list) { list.is_public = checked ? 1 : 0; list.public_token = res.public_token; }
        if (this.currentList && this.currentList.id == this.sharingListId) {
            this.currentList.is_public = checked ? 1 : 0;
            this.currentList.public_token = res.public_token;
        }
        this.toast(checked ? 'List is now public' : 'List is now private');
        this.renderLists();
    }

    copyShareLink() {
        const input = document.getElementById('shareLinkInput');
        navigator.clipboard.writeText(input.value).then(() => this.toast('Link copied!'));
    }

    closeCreateModal() {
        document.getElementById('createModal').classList.remove('active');
    }

    async saveList() {
        const name = document.getElementById('listName').value.trim();
        const desc = document.getElementById('listDesc').value.trim();
        if (!name) { this.toast('Please enter a name'); return; }

        if (this.editingListId) {
            await this.api('updateList', { id: this.editingListId, name, description: desc }, 'POST');
            this.toast('List updated');
        } else {
            const res = await this.api('createList', { name, description: desc }, 'POST');
            this.toast('List created');
            this.closeCreateModal();
            await this.loadLists();
            // Drop the user straight into the new list so they can add leads immediately.
            if (res && res.id) { await this.openList(res.id); }
            return;
        }
        this.closeCreateModal();
        await this.loadLists();
    }

    async deleteList(id) {
        const list = this.lists.find(l => l.id == id);
        const count = list ? (list.total || 0) : 0;
        const msg = count > 0
            ? `Are you sure?\n\nThis will permanently delete "${list.name}" and all ${count.toLocaleString()} leads inside it.\n\nThis cannot be undone.`
            : `Are you sure you want to delete "${list?.name || 'this list'}"?\n\nThis cannot be undone.`;
        if (!confirm(msg)) return;
        await this.api('deleteList', { id }, 'POST');
        this.toast('List deleted');
        await this.loadLists();
    }

    // DETAIL VIEW

    async openList(id) {
        const data = await this.api('getListDetail', { id });
        if (!data.success) { this.toast('Error loading list'); return; }

        this.currentList = data.list;
        this.currentPage = 1;
        this.searchQuery = '';
        this.hasFilter = '';
        this.selectedLeads.clear();
        document.getElementById('leadSearch').value = '';

        document.getElementById('listsView').classList.add('hidden');
        document.getElementById('detailView').classList.remove('hidden');
        document.getElementById('detailName').textContent = this.currentList.name;
        document.getElementById('detailSub').textContent = this.currentList.description || '';

        const ppSelect = document.getElementById('perPageSelect');
        if (ppSelect) ppSelect.value = this.perPage;

        this.renderStats();
        this.renderSearchedAreas();
        await this.loadLeads();
        setTimeout(() => { this.initListMap(); this.loadMapLeads(); }, 100);

        document.querySelectorAll('#filterPills .filter-pill').forEach(p => {
            p.classList.toggle('active', p.dataset.filter === '');
        });

        this.checkEnrichmentStatus(id);
    }

    async checkEnrichmentStatus(listId) {
        const data = await this.api('getEnrichmentProgress', { list_id: listId });
        if (!data || !data.success) return;

        if (data.needs_enrichment > 0 || data.pending > 0) {
            this.enrichmentPollId = listId;
            this._recoveryAttempts = 0;
            this._totalRecoveryAttempts = 0;
            this._failedRetryDone = false;
            while (true) {
                const r = await this.api('fireAllScrapes', { list_id: listId, batch_size: 100 }, 'POST');
                if (!r.success) break;
                if (typeof r.credits !== 'undefined') { this.credits = r.credits; this.updateCreditsDisplay(); }
                if (r.out_of_credits) { this.toast('Out of credits — enrichment paused. Add credits to finish.'); break; }
                const fired = r.fired || 0;
                if (fired === 0 || (r.pending || 0) === 0) break;
                await new Promise(rv => setTimeout(rv, 300));
            }
            this._enrichLiveLoop(listId);
        } else if (data.processing > 0) {
            this.enrichmentPollId = listId;
            this._recoveryAttempts = 0;
            this._totalRecoveryAttempts = 0;
            this._failedRetryDone = false;
            this._enrichLiveLoop(listId);
        }
    }

    goBack() {
        this.enrichmentPollId = null;
        this._recoveryAttempts = 0;
        this._totalRecoveryAttempts = 0;
        this._failedRetryDone = false;
        this.currentList = null;
        this.currentLeads = [];
        this.allMapLeads = [];
        this.listMapMarkers = [];
        if (this.listMap) { this.listMap.remove(); this.listMap = null; }
        document.getElementById('detailView').classList.add('hidden');
        document.getElementById('listsView').classList.remove('hidden');
        this.loadLists();
    }

    renderStats() {
        const s = this.currentList?.stats || {};
        const total = parseInt(s.total) || 0;
        const visited = parseInt(s.websites_visited) || 0;
        const reachedOut = parseInt(s.reached_out_count) || 0;
        const hasPhone = parseInt(s.has_phone) || 0;
        const hasEmail = parseInt(s.has_email) || 0;
        const hasSocials = parseInt(s.has_socials) || 0;
        const hasNotes = parseInt(s.has_notes) || 0;
        const emailed = parseInt(s.emailed_count) || 0;
        const igDm = parseInt(s.ig_dm_count) || 0;
        const stageNew = parseInt(s.stage_new) || 0;
        const stageContacted = parseInt(s.stage_contacted) || 0;
        const stageEngaged = parseInt(s.stage_engaged) || 0;
        const stageClient = parseInt(s.stage_client) || 0;
        const stageNoResponse = parseInt(s.stage_no_response) || 0;
        const contacted = stageContacted + stageEngaged + stageClient + stageNoResponse;
        const responseRate = contacted > 0 ? ((stageEngaged + stageClient) / contacted * 100).toFixed(1) : '0.0';
        const conversionRate = (stageEngaged + stageClient) > 0 ? (stageClient / (stageEngaged + stageClient) * 100).toFixed(1) : '0.0';

        const hf = this.hasFilter;

        const maxStage = Math.max(stageNew, stageContacted, stageEngaged, stageClient, stageNoResponse, 1);
        const barPct = (v) => Math.max(v > 0 ? 8 : 0, (v / maxStage) * 100);

        // Top stats (above map)
        document.getElementById('topStatsRow').innerHTML = `
        <div class="stats-row" style="margin-bottom:20px;">
            <div class="stat-card accent clickable ${hf === '' ? 'active-filter' : ''}" onclick="app.clearAllFilters()"><div class="stat-value">${total.toLocaleString()}</div><div class="stat-label">Total Leads</div></div>
            <div class="stat-card clickable ${hf === 'visited' ? 'active-filter' : ''}" onclick="app.filterByHas('visited')"><div class="stat-value" style="color:var(--accent);">${visited.toLocaleString()}</div><div class="stat-label">Visited</div></div>
            <div class="stat-card clickable ${hf === 'email' ? 'active-filter' : ''}" onclick="app.filterByHas('email')"><div class="stat-value" style="color:var(--purple);">${hasEmail.toLocaleString()}</div><div class="stat-label">Have Email</div></div>
            <div class="stat-card clickable ${hf === 'socials' ? 'active-filter' : ''}" onclick="app.filterByHas('socials')"><div class="stat-value" style="color:var(--teal);">${hasSocials.toLocaleString()}</div><div class="stat-label">Have Socials</div></div>
            <div class="stat-card clickable ${hf === 'phone' ? 'active-filter' : ''}" onclick="app.filterByHas('phone')"><div class="stat-value" style="color:var(--green);">${hasPhone.toLocaleString()}</div><div class="stat-label">Have Phone</div></div>
            <div class="stat-card clickable ${hf === 'notes' ? 'active-filter' : ''}" onclick="app.filterByHas('notes')"><div class="stat-value" style="color:var(--orange);">${hasNotes.toLocaleString()}</div><div class="stat-label">Have Notes</div></div>
        </div>`;

        // Pipeline + metrics (below map)
        let pipelineHtml = `<div class="pipeline-funnel" style="margin-bottom:16px;">
            <div class="pipeline-funnel-stage ${hf === 'stage_new' ? 'active-filter' : ''}" onclick="app.filterByHas('stage_new')">
                <div class="pf-count" style="color:var(--text-secondary);">${stageNew}</div>
                <div class="pf-label">New</div>
                <div class="pf-bar" style="background:var(--bg-secondary);width:${barPct(stageNew)}%;margin-left:auto;margin-right:auto;"></div>
            </div>
            <div class="pipeline-funnel-arrow"><i class="fas fa-chevron-right"></i></div>
            <div class="pipeline-funnel-stage ${hf === 'stage_contacted' ? 'active-filter' : ''}" onclick="app.filterByHas('stage_contacted')">
                <div class="pf-count" style="color:var(--accent);">${stageContacted}</div>
                <div class="pf-label">Contacted</div>
                <div class="pf-bar" style="background:var(--accent);width:${barPct(stageContacted)}%;margin-left:auto;margin-right:auto;"></div>
            </div>
            <div class="pipeline-funnel-arrow"><i class="fas fa-chevron-right"></i></div>
            <div class="pipeline-funnel-stage ${hf === 'stage_engaged' ? 'active-filter' : ''}" onclick="app.filterByHas('stage_engaged')">
                <div class="pf-count" style="color:var(--orange);">${stageEngaged}</div>
                <div class="pf-label">Engaged</div>
                <div class="pf-bar" style="background:var(--orange);width:${barPct(stageEngaged)}%;margin-left:auto;margin-right:auto;"></div>
            </div>
            <div class="pipeline-funnel-arrow"><i class="fas fa-chevron-right"></i></div>
            <div class="pipeline-funnel-stage ${hf === 'stage_client' ? 'active-filter' : ''}" onclick="app.filterByHas('stage_client')">
                <div class="pf-count" style="color:var(--green);">${stageClient}</div>
                <div class="pf-label">Client</div>
                <div class="pf-bar" style="background:var(--green);width:${barPct(stageClient)}%;margin-left:auto;margin-right:auto;"></div>
            </div>
            <div class="pipeline-funnel-arrow"><i class="fas fa-chevron-right"></i></div>
            <div class="pipeline-funnel-stage ${hf === 'stage_no_response' ? 'active-filter' : ''}" onclick="app.filterByHas('stage_no_response')">
                <div class="pf-count" style="color:var(--red);">${stageNoResponse}</div>
                <div class="pf-label">No Response</div>
                <div class="pf-bar" style="background:var(--red);width:${barPct(stageNoResponse)}%;margin-left:auto;margin-right:auto;"></div>
            </div>
            <div style="width:1px;background:var(--card-border);margin:8px 0;"></div>
            <div class="pipeline-funnel-stage" style="cursor:default;flex:0.6;">
                <div style="display:flex;gap:12px;justify-content:center;align-items:baseline;">
                    <span style="font-size:11px;color:var(--purple);font-weight:600;"><i class="fas fa-envelope" style="margin-right:3px;"></i>${emailed}</span>
                    <span style="font-size:11px;color:var(--pink);font-weight:600;"><i class="fas fa-comment-dots" style="margin-right:3px;"></i>${igDm}</span>
                </div>
                <div style="display:flex;gap:10px;justify-content:center;margin-top:6px;">
                    <span style="font-size:10px;color:var(--text-secondary);"><span style="font-weight:700;color:var(--orange);">${responseRate}%</span> resp</span>
                    <span style="font-size:10px;color:var(--text-secondary);"><span style="font-weight:700;color:var(--green);">${conversionRate}%</span> conv</span>
                </div>
            </div>
        </div>`;

        document.getElementById('statsRow').innerHTML = pipelineHtml;
    }

    renderSearchedAreas() {
        const searches = this.currentList?.state_stats || [];
        const area = document.getElementById('searchedStatesArea');
        const container = document.getElementById('searchedStates');

        if (searches.length === 0) { area.classList.add('hidden'); return; }

        area.classList.remove('hidden');
        container.innerHTML = searches.map(s =>
            `<span class="searched-tag"><i class="fas fa-check-circle" style="color:var(--green);margin-right:4px;"></i>${this.esc(s.state_name)} (${s.cities_searched} cities)</span>`
        ).join('');

        this.searchedCitiesForList = new Set();
        (this.currentList?.searches || []).forEach(s => {
            if (s.city) this.searchedCitiesForList.add(`${s.city}|${s.state_name}`);
        });
    }

    async loadLeads() {
        if (!this.currentList) return;
        const params = {
            list_id: this.currentList.id,
            page: this.currentPage,
            per_page: this.perPage,
            search: this.searchQuery
        };
        if (this.hasFilter) params.has = this.hasFilter;
        if (this.hasFilter === 'selected') params.selected_ids = Array.from(this.selectedLeads);
        const method = this.hasFilter === 'selected' ? 'POST' : undefined;
        const data = await this.api('getLeads', params, method);
        if (data.success) {
            this.currentLeads = data.leads;
            this.totalPages = data.total_pages;
            this.totalLeads = data.total;
            this.renderLeads();
            this.renderPagination();
            this.updateSelectionUI();
            if (this.listMap) this.loadMapLeads();
        }
    }

    renderLeads() {
        const body = document.getElementById('leadsBody');
        const empty = document.getElementById('leadsEmpty');

        const addLeadsBtn = document.querySelector('.detail-header .btn-primary[onclick*="openAddLeadsModal"]');
        if (this.currentLeads.length === 0) {
            body.innerHTML = '';
            empty.classList.remove('hidden');
            if (addLeadsBtn) addLeadsBtn.classList.add('pulse-cue');
            return;
        }
        if (addLeadsBtn) addLeadsBtn.classList.remove('pulse-cue');
        empty.classList.add('hidden');

        const stageLabels = { new: 'New', contacted: 'Contacted', engaged: 'Engaged', client: 'Client', no_response: 'No Response' };

        body.innerHTML = this.currentLeads.map(lead => {
            const emails = lead.emails || [];
            const socials = lead.social_media_links || [];
            const hasEmail = emails.length > 0;
            const hasSocial = socials.length > 0;
            const hasPhone = !!lead.phone;
            const hasWeb = !!lead.website;
            const checked = this.selectedLeads.has(lead.id) ? 'checked' : '';
            const stage = lead.pipeline_stage || 'new';
            const fups = parseInt(lead.follow_up_count) || 0;
            const contactDate = lead.first_contacted_at ? new Date(lead.first_contacted_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }) : '';
            const eStatus = lead.enrichment_status;
            const enrichIcon = !hasWeb ? ''
                : eStatus === 'completed' ? '<span class="enrich-dot enrich-done" title="Website searched"></span>'
                : eStatus === 'processing' ? '<span class="enrich-dot enrich-working" title="Searching..."></span>'
                : eStatus === 'pending' ? '<span class="enrich-dot enrich-queued" title="Queued"></span>'
                : eStatus === 'failed' ? '<span class="enrich-dot enrich-fail" title="Search failed"></span>'
                : '<span class="enrich-dot enrich-waiting" title="Not yet searched"></span>';

            return `<tr>
                <td class="checkbox-wrap"><input type="checkbox" ${checked} onchange="app.toggleLeadSelect(${lead.id}, this.checked)"></td>
                <td>
                    <div class="lead-name" style="cursor:pointer;" onclick="app.openLeadDetail(${lead.id})">${this.esc(lead.business_name || 'Unknown')}</div>
                    ${lead.types ? `<div style="font-size:11px;color:var(--text-tertiary);margin-top:2px;">${this.esc(lead.types).substring(0, 50)}</div>` : ''}
                </td>
                <td><div class="lead-location">${this.esc(lead.city || '')}${lead.city && lead.state ? ', ' : ''}${this.esc(lead.state || '')}</div></td>
                <td>
                    <div class="lead-contact-icons">
                        ${hasPhone ? `<button class="contact-icon has" title="${this.esc(lead.phone)}" onclick="event.stopPropagation();navigator.clipboard.writeText('${this.esc(lead.phone)}');app.toast('Phone copied')"><i class="fas fa-phone"></i></button>` : `<span class="contact-icon none"><i class="fas fa-phone"></i></span>`}
                        ${hasEmail
                            ? `<button class="contact-icon has" onmouseenter="app.showEmailPopover(this, ${lead.id})" onclick="event.stopPropagation();" style="position:relative;"><i class="fas fa-envelope"></i>${emails.length > 1 ? `<span style="position:absolute;top:-4px;right:-4px;background:var(--accent);color:#fff;font-size:8px;font-weight:700;width:14px;height:14px;border-radius:50%;display:flex;align-items:center;justify-content:center;">${emails.length}</span>` : ''}</button>`
                        : `<span class="contact-icon none"><i class="fas fa-envelope"></i></span>`}
                        ${hasWeb ? `<button class="contact-icon ${lead.visited_website == 1 ? 'tracked' : 'has'}" title="Visit website" onclick="event.stopPropagation();app.openWebPreview('${this.esc(lead.website)}', ${lead.id})" style="position:relative;"><i class="fas fa-globe"></i>${enrichIcon}</button>` : `<span class="contact-icon none"><i class="fas fa-globe"></i></span>`}
                    </div>
                </td>
                <td>
                    <div class="lead-contact-icons">
                        ${hasSocial ? this.renderSocialIcons(lead) : `<span class="contact-icon none"><i class="fas fa-share-nodes"></i></span>`}
                    </div>
                </td>
                <td>
                    <span class="pipeline-badge stage-${stage}" onclick="event.stopPropagation();app.cyclePipelineStage(${lead.id})" title="Click to change stage">${stageLabels[stage] || 'New'}</span>
                </td>
                <td>
                    <div class="outreach-icons">
                        <button class="outreach-icon ${lead.outreach_email == 1 ? 'done' : 'not-done'}" title="${lead.outreach_email == 1 ? 'Email sent' : 'Mark email sent'}" onclick="event.stopPropagation();app.toggleOutreach(${lead.id}, 'outreach_email', ${lead.outreach_email == 1 ? 0 : 1})"><i class="fas fa-envelope"></i></button>
                        <button class="outreach-icon ${lead.outreach_instagram == 1 ? 'done' : 'not-done'}" title="${lead.outreach_instagram == 1 ? 'DM sent' : 'Mark DM sent'}" onclick="event.stopPropagation();app.toggleOutreach(${lead.id}, 'outreach_instagram', ${lead.outreach_instagram == 1 ? 0 : 1})"><i class="fas fa-comment-dots"></i></button>
                    </div>
                </td>
                <td>
                    <div class="followup-counter">
                        <button class="followup-btn" onclick="event.stopPropagation();app.decrementFollowUp(${lead.id})" title="Remove follow-up" ${fups === 0 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-minus" style="font-size:8px;"></i></button>
                        <span class="count" style="color:${fups > 0 ? 'var(--accent)' : 'var(--text-tertiary)'}">${fups}</span>
                        <button class="followup-btn" onclick="event.stopPropagation();app.incrementFollowUp(${lead.id})" title="Add follow-up" ${fups >= 4 ? 'disabled style="opacity:0.3;"' : ''}><i class="fas fa-plus" style="font-size:8px;"></i></button>
                    </div>
                </td>
                <td>
                    <span style="font-size:12px;color:${contactDate ? 'var(--text-secondary)' : 'var(--text-tertiary)'};">${contactDate || '—'}</span>
                </td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <button class="btn-icon" onclick="event.stopPropagation();app.showNotePopover(this, ${lead.id})" title="${lead.notes ? 'View/edit notes' : 'Add note'}" style="position:relative;${lead.notes ? 'color:var(--orange);' : ''}"><i class="fas fa-sticky-note" style="font-size:12px;"></i>${lead.notes ? '<span style="position:absolute;top:-2px;right:-2px;width:8px;height:8px;border-radius:50%;background:var(--orange);"></span>' : ''}</button>
                        <button class="btn-icon" onclick="event.stopPropagation();app.openLeadDetail(${lead.id})" title="Details"><i class="fas fa-expand" style="font-size:12px;"></i></button>
                        <button class="btn-icon" onclick="event.stopPropagation();app.deleteLead(${lead.id})" title="Delete"><i class="fas fa-trash" style="font-size:12px;color:var(--red);"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    renderSocialIcons(lead) {
        const socials = lead.social_media_links || [];
        if (socials.length === 0) return '';
        const visited = lead.visited_socials || [];

        const visible = socials.slice(0, 3).map(url => {
            const platform = SOCIAL_PLATFORMS.find(p => p.patterns.some(pat => url.toLowerCase().includes(pat)));
            const icon = platform ? platform.icon : 'fas fa-link';
            const isVisited = visited.includes(url);
            return `<button class="contact-icon ${isVisited ? 'tracked' : 'has'}" title="${platform ? platform.name : 'Social'}" onclick="event.stopPropagation();app.trackSocialVisit(${lead.id}, '${this.esc(url)}')"><i class="${icon}"></i></button>`;
        }).join('');
        const moreCount = socials.length - 3;
        const moreBtn = moreCount > 0 ? `<button class="contact-icon has" style="font-size:10px;font-weight:600;min-width:24px;padding:0 4px;" title="Show ${moreCount} more" onclick="event.stopPropagation();app.expandSocials(${lead.id}, this)">+${moreCount}</button>` : '';
        return visible + moreBtn;
    }

    renderPagination() {
        const area = document.getElementById('paginationArea');
        if (this.totalPages <= 1) { area.innerHTML = ''; return; }

        let html = `<button class="page-btn" onclick="app.goToPage(${this.currentPage - 1})" ${this.currentPage <= 1 ? 'disabled' : ''}><i class="fas fa-chevron-left"></i></button>`;

        const maxVisible = 7;
        let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
        let end = Math.min(this.totalPages, start + maxVisible - 1);
        if (end - start < maxVisible - 1) start = Math.max(1, end - maxVisible + 1);

        if (start > 1) { html += `<button class="page-btn" onclick="app.goToPage(1)">1</button>`; if (start > 2) html += `<span style="padding:0 4px;color:var(--text-tertiary);">...</span>`; }
        for (let i = start; i <= end; i++) {
            html += `<button class="page-btn ${i === this.currentPage ? 'active' : ''}" onclick="app.goToPage(${i})">${i}</button>`;
        }
        if (end < this.totalPages) { if (end < this.totalPages - 1) html += `<span style="padding:0 4px;color:var(--text-tertiary);">...</span>`; html += `<button class="page-btn" onclick="app.goToPage(${this.totalPages})">${this.totalPages}</button>`; }

        html += `<button class="page-btn" onclick="app.goToPage(${this.currentPage + 1})" ${this.currentPage >= this.totalPages ? 'disabled' : ''}><i class="fas fa-chevron-right"></i></button>`;
        html += `<span style="margin-left:12px;font-size:13px;color:var(--text-secondary);">${this.totalLeads.toLocaleString()} leads</span>`;
        area.innerHTML = html;
    }

    async goToPage(page) {
        if (page < 1 || page > this.totalPages) return;
        this.currentPage = page;
        await this.loadLeads();
        document.querySelector('.leads-table-wrap')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    debounceSearch() {
        clearTimeout(this.searchDebounce);
        this.searchDebounce = setTimeout(() => {
            this.searchQuery = document.getElementById('leadSearch').value.trim();
            this.currentPage = 1;
            this.loadLeads();
            this.updateListMap();
        }, 300);
    }

    filterByHas(type) {
        this.hasFilter = this.hasFilter === type ? '' : type;
        this.currentPage = 1;
        document.querySelectorAll('#filterPills .filter-pill').forEach(p => p.classList.toggle('active', (p.dataset.filter || '') === this.hasFilter));
        this.renderStats();
        this.loadLeads();
        this.updateListMap();
    }

    clearAllFilters() {
        this.hasFilter = '';
        this.currentPage = 1;
        document.querySelectorAll('#filterPills .filter-pill').forEach(p => p.classList.toggle('active', p.dataset.filter === ''));
        this.renderStats();
        this.loadLeads();
        this.updateListMap();
    }

    // SELECTION

    toggleLeadSelect(id, checked) {
        if (checked) this.selectedLeads.add(id);
        else this.selectedLeads.delete(id);
        this.updateSelectionUI();
        if (this.hasFilter === 'selected') this.updateListMap();
    }

    toggleSelectAll(checked) {
        if (checked) {
            this.currentLeads.forEach(l => this.selectedLeads.add(l.id));
        } else {
            this.currentLeads.forEach(l => this.selectedLeads.delete(l.id));
        }
        this.renderLeads();
        this.updateSelectionUI();
        if (this.hasFilter === 'selected') this.updateListMap();
    }

    updateSelectionUI() {
        const cb = document.getElementById('selectAllCheckbox');
        const bulkActions = document.getElementById('bulkActions');
        const countEl = document.getElementById('bulkSelectedCount');
        if (cb) {
            const pageIds = new Set(this.currentLeads.map(l => l.id));
            const pageSelected = this.currentLeads.filter(l => this.selectedLeads.has(l.id)).length;
            cb.checked = this.currentLeads.length > 0 && pageSelected === this.currentLeads.length;
            cb.indeterminate = pageSelected > 0 && pageSelected < this.currentLeads.length;
        }
        if (this.selectedLeads.size > 0) {
            bulkActions.classList.remove('hidden');
            bulkActions.style.display = 'flex';
            const isFiltering = this.hasFilter === 'selected';
            countEl.innerHTML = `<span style="cursor:pointer;text-decoration:underline;text-underline-offset:2px;" onclick="app.filterByHas('${isFiltering ? '' : 'selected'}')" title="${isFiltering ? 'Show all leads' : 'Show only selected'}">${this.selectedLeads.size} selected</span>`;
        } else {
            bulkActions.classList.add('hidden');
            bulkActions.style.display = 'none';
        }
    }

    changePerPage(val) {
        this.perPage = parseInt(val);
        this.currentPage = 1;
        this.selectedLeads.clear();
        this.loadLeads();
    }

    async bulkDeleteLeads() {
        if (!confirm(`Delete ${this.selectedLeads.size} selected leads?`)) return;
        await this.api('deleteLeads', { ids: Array.from(this.selectedLeads) }, 'POST');
        this.selectedLeads.clear();
        this.updateSelectionUI();
        this.toast('Leads deleted');
        await this.refreshCurrentList();
    }

    async bulkSetVisited(val) {
        await this.api('bulkUpdateLeads', { ids: Array.from(this.selectedLeads), field: 'visited_website', value: val ? 1 : 0 }, 'POST');
        this.toast(`${this.selectedLeads.size} leads marked as ${val ? 'visited' : 'unvisited'}`);
        this.selectedLeads.clear();
        this.updateSelectionUI();
        await this.refreshCurrentList();
    }

    async bulkSetReachedOut(val) {
        await this.api('bulkUpdateLeads', { ids: Array.from(this.selectedLeads), field: 'reached_out', value: val ? 1 : 0 }, 'POST');
        this.toast(`${this.selectedLeads.size} leads marked as ${val ? 'reached out' : 'not reached out'}`);
        this.selectedLeads.clear();
        this.updateSelectionUI();
        await this.refreshCurrentList();
    }

    async bulkSetField(field, value) {
        await this.api('bulkUpdateLeads', { ids: Array.from(this.selectedLeads), field, value }, 'POST');
        this.toast(`${this.selectedLeads.size} leads updated`);
        this.selectedLeads.clear();
        this.updateSelectionUI();
        await this.refreshCurrentList();
    }

    async deleteLead(id) {
        if (!confirm('Delete this lead?')) return;
        await this.api('deleteLead', { id }, 'POST');
        this.toast('Lead deleted');
        await this.refreshCurrentList();
    }

    // TRACKING

    async trackWebsiteVisit(leadId, url) {
        window.open(url, '_blank');
        await this.api('updateLead', { id: leadId, visited_website: 1 }, 'POST');
        await this.refreshLeadsOnly();
    }

    async trackSocialVisit(leadId, url) {
        window.open(url, '_blank');
        const lead = this.currentLeads.find(l => l.id == leadId);
        const visited = lead ? [...new Set([...(lead.visited_socials || []), url])] : [url];
        await this.api('updateLead', { id: leadId, visited_social: 1, visited_socials: visited }, 'POST');
        if (lead) lead.visited_socials = visited;
        await this.refreshCurrentList();
    }

    expandSocials(leadId, btnEl) {
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;
        const socials = lead.social_media_links || [];
        if (socials.length <= 3) return;
        const extra = socials.slice(3);
        const visited = lead.visited_socials || [];
        let html = '';
        extra.forEach(url => {
            const platform = SOCIAL_PLATFORMS.find(p => p.patterns.some(pat => url.toLowerCase().includes(pat)));
            const icon = platform ? platform.icon : 'fas fa-link';
            const isVisited = visited.includes(url);
            html += `<button class="contact-icon ${isVisited ? 'tracked' : 'has'}" title="${platform ? platform.name : 'Social'}" onclick="event.stopPropagation();app.trackSocialVisit(${leadId}, '${this.esc(url)}')"><i class="${icon}"></i></button>`;
        });
        const wrap = document.createElement('span');
        wrap.innerHTML = html;
        btnEl.insertAdjacentHTML('afterend', wrap.innerHTML);
        btnEl.remove();
    }

    async toggleVisited(leadId, checked) {
        await this.api('updateLead', { id: leadId, visited_website: checked ? 1 : 0 }, 'POST');
        await this.refreshCurrentList();
    }

    async toggleReachedOut(leadId, checked) {
        await this.api('updateLead', { id: leadId, reached_out: checked ? 1 : 0 }, 'POST');
        await this.refreshCurrentList();
    }

    async refreshLeadsOnly() {
        await this.loadLeads();
        if (this.currentList) {
            const data = await this.api('getListDetail', { id: this.currentList.id });
            if (data.success) {
                this.currentList = data.list;
                this.renderStats();
            }
        }
    }

    async refreshCurrentList() {
        if (this.currentList) {
            const data = await this.api('getListDetail', { id: this.currentList.id });
            if (data.success) {
                this.currentList = data.list;
                this.renderStats();
                this.renderSearchedAreas();
            }
        }
        await this.loadLeads();
    }

    // LEAD DETAIL SLIDE

    openLeadDetail(id) {
        const lead = this.currentLeads.find(l => l.id == id);
        if (!lead) return;
        this.currentLeadDetail = lead;

        document.getElementById('slideLeadName').textContent = lead.business_name || 'Unknown';
        document.getElementById('slideLeadLocation').textContent = `${lead.city || ''}${lead.city && lead.state ? ', ' : ''}${lead.state || ''}`;

        // Gallery
        const gallerySection = document.getElementById('slideGallerySection');
        const galleryEl = document.getElementById('slideGallery');
        let rawData = null;
        try { rawData = typeof lead.raw_data === 'string' ? JSON.parse(lead.raw_data) : lead.raw_data; } catch(e) {}
        const photos = rawData?.photos || rawData?.photos_sample || [];
        this.galleryPhotos = [];
        if (photos.length > 0) {
            photos.forEach(p => {
                const thumb = typeof p === 'string' ? p : (p.photo_url || p.src || '');
                const hd = typeof p === 'string' ? p : (p.photo_url_large || p.photo_url || p.src || '');
                if (thumb) this.galleryPhotos.push({ thumb, hd });
            });
        }
        if (this.galleryPhotos.length > 0) {
            gallerySection.classList.remove('hidden');
            galleryEl.innerHTML = this.galleryPhotos.map((p, i) =>
                `<img class="gallery-thumb" src="${this.esc(p.thumb)}" onclick="app.openLightbox(${i})" onerror="this.style.display='none'" alt="Photo">`
            ).join('');
        } else {
            gallerySection.classList.add('hidden');
            galleryEl.innerHTML = '';
        }

        // Map
        const mapSection = document.getElementById('slideMapSection');
        const mapEl = document.getElementById('slideMap');
        const lat = parseFloat(lead.latitude) || (rawData?.latitude) || null;
        const lng = parseFloat(lead.longitude) || (rawData?.longitude) || null;
        if (lat && lng) {
            mapSection.classList.remove('hidden');
            if (this.slideMap) { this.slideMap.remove(); this.slideMap = null; }
            setTimeout(() => {
                this.slideMap = L.map('slideMap').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(this.slideMap);
                L.marker([lat, lng]).addTo(this.slideMap).bindPopup(this.esc(lead.business_name || ''));
            }, 100);
        } else {
            mapSection.classList.add('hidden');
            if (this.slideMap) { this.slideMap.remove(); this.slideMap = null; }
        }

        // Contact Info
        const contact = [];
        if (lead.phone) contact.push(`<div class="info-row"><i class="fas fa-phone"></i><span>${this.esc(lead.phone)}</span></div>`);
        if (lead.website) contact.push(`<div class="info-row"><i class="fas fa-globe"></i><a href="#" onclick="event.preventDefault();app.openWebPreview('${this.esc(lead.website)}', ${lead.id})">${this.esc(lead.website)}</a></div>`);
        const emails = lead.emails || [];
        emails.forEach(e => contact.push(`<div class="info-row" style="justify-content:space-between;"><div style="display:flex;align-items:center;gap:10px;min-width:0;"><i class="fas fa-envelope"></i><a href="#" onclick="event.preventDefault();app.sendTemplateEmail('${this.esc(e)}', ${lead.id})" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${this.esc(e)}</a></div><button onclick="event.preventDefault();navigator.clipboard.writeText('${this.esc(e)}');app.toast('Email copied')" style="background:none;border:none;cursor:pointer;color:var(--text-tertiary);padding:4px 6px;border-radius:6px;transition:all 0.15s;flex-shrink:0;" onmouseover="this.style.color='var(--accent)';this.style.background='var(--accent-light)'" onmouseout="this.style.color='var(--text-tertiary)';this.style.background='none'" title="Copy email"><i class="fas fa-copy" style="font-size:12px;"></i></button></div>`));
        if (lead.address) {
            const mapLink = lat && lng ? `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=16/${lat}/${lng}` : `https://www.openstreetmap.org/search?query=${encodeURIComponent(lead.address)}`;
            contact.push(`<div class="info-row"><i class="fas fa-map-marker-alt"></i><span>${this.esc(lead.address)}</span> <a href="${mapLink}" target="_blank" style="margin-left:6px;flex-shrink:0;" title="Open in map"><i class="fas fa-map" style="color:var(--accent);"></i></a></div>`);
        }
        document.getElementById('slideContact').innerHTML = contact.length ? contact.join('') : '<div style="color:var(--text-tertiary);font-size:13px;">No contact info</div>';

        // Socials
        const socials = lead.social_media_links || [];
        const visitedSocials = lead.visited_socials || [];
        if (socials.length > 0) {
            document.getElementById('slideSocials').innerHTML = socials.map(url => {
                const p = SOCIAL_PLATFORMS.find(pl => pl.patterns.some(pat => url.toLowerCase().includes(pat)));
                const isVisited = visitedSocials.includes(url);
                return `<div class="info-row"><i class="${p ? p.icon : 'fas fa-link'}" style="color:${isVisited ? 'var(--green)' : 'inherit'};"></i><a href="#" onclick="event.preventDefault();app.trackSocialVisit(${lead.id}, '${this.esc(url)}')" style="${isVisited ? 'color:var(--green);' : ''}">${this.esc(url)}</a></div>`;
            }).join('');
        } else {
            document.getElementById('slideSocials').innerHTML = '<div style="color:var(--text-tertiary);font-size:13px;">No social media found</div>';
        }

        document.getElementById('slideNotes').value = lead.notes || '';

        // Details
        const details = [];
        if (lead.rating) details.push(`<div class="info-row"><i class="fas fa-star" style="color:var(--orange);"></i><span>${lead.rating} stars (${lead.review_count || 0} reviews)</span></div>`);
        if (lead.types) details.push(`<div class="info-row"><i class="fas fa-tag"></i><span>${this.esc(lead.types)}</span></div>`);
        details.push(`<div class="info-row"><i class="fas fa-clock"></i><span>Added ${new Date(lead.created_at).toLocaleDateString()}</span></div>`);
        if (lead.website_visited_at) details.push(`<div class="info-row"><i class="fas fa-globe" style="color:var(--green);"></i><span>Website visited ${new Date(lead.website_visited_at).toLocaleDateString()}</span></div>`);
        if (lead.social_visited_at) details.push(`<div class="info-row"><i class="fas fa-share-nodes" style="color:var(--green);"></i><span>Social visited ${new Date(lead.social_visited_at).toLocaleDateString()}</span></div>`);
        if (lead.reached_out_at) details.push(`<div class="info-row"><i class="fas fa-paper-plane" style="color:var(--green);"></i><span>Reached out ${new Date(lead.reached_out_at).toLocaleDateString()}</span></div>`);
        document.getElementById('slideDetails').innerHTML = details.join('');

        // Pipeline & Outreach fields
        document.getElementById('slidePipelineStage').value = lead.pipeline_stage || 'new';
        document.getElementById('slideOutreachEmail').checked = lead.outreach_email == 1;
        document.getElementById('slideOutreachInstagram').checked = lead.outreach_instagram == 1;
        this.slideRenderFollowUps(parseInt(lead.follow_up_count) || 0);
        const fcDate = lead.first_contacted_at ? new Date(lead.first_contacted_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : null;
        document.getElementById('slideFirstContact').innerHTML = fcDate
            ? `<i class="fas fa-calendar" style="margin-right:4px;"></i> First contacted: ${fcDate}`
            : '<i class="fas fa-calendar" style="margin-right:4px;color:var(--text-tertiary);"></i> Not yet contacted';

        document.getElementById('slideBackdrop').classList.add('open');
        document.getElementById('slidePanel').classList.add('open');
    }

    slideRenderFollowUps(count) {
        const dots = document.getElementById('slideFollowUpDots');
        const label = document.getElementById('slideFollowUpLabel');
        label.textContent = count === 0 ? 'None' : `${count} of 4`;
        dots.innerHTML = [0,1,2,3].map(i =>
            `<div style="flex:1;height:8px;border-radius:4px;background:${i < count ? 'var(--accent)' : 'var(--card-border)'};transition:background 0.2s;"></div>`
        ).join('');
    }

    async slideChangeFollowUp(delta) {
        if (!this.currentLeadDetail) return;
        const current = parseInt(this.currentLeadDetail.follow_up_count) || 0;
        const newVal = Math.max(0, Math.min(4, current + delta));
        if (newVal === current) return;
        this.currentLeadDetail.follow_up_count = newVal;
        this.slideRenderFollowUps(newVal);
        await this.api('updateLead', { id: this.currentLeadDetail.id, follow_up_count: newVal }, 'POST');
        await this.refreshCurrentList();
    }

    async updatePipelineStage(stage) {
        if (!this.currentLeadDetail) return;
        this.currentLeadDetail.pipeline_stage = stage;
        await this.api('updateLead', { id: this.currentLeadDetail.id, pipeline_stage: stage }, 'POST');
        await this.refreshCurrentList();
    }

    async updateOutreach(field, checked) {
        if (!this.currentLeadDetail) return;
        this.currentLeadDetail[field] = checked ? 1 : 0;
        const update = { id: this.currentLeadDetail.id, [field]: checked ? 1 : 0 };
        if (checked && this.currentLeadDetail.pipeline_stage === 'new') {
            update.pipeline_stage = 'contacted';
            this.currentLeadDetail.pipeline_stage = 'contacted';
            document.getElementById('slidePipelineStage').value = 'contacted';
        }
        await this.api('updateLead', update, 'POST');
        const fcEl = document.getElementById('slideFirstContact');
        if (checked && !this.currentLeadDetail.first_contacted_at) {
            this.currentLeadDetail.first_contacted_at = new Date().toISOString();
            fcEl.innerHTML = `<i class="fas fa-calendar" style="margin-right:4px;"></i> First contacted: ${new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' })}`;
        }
        await this.refreshCurrentList();
    }

    async toggleOutreach(leadId, field, value) {
        const update = { id: leadId, [field]: value };
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (lead) {
            lead[field] = value;
            if (value && (!lead.pipeline_stage || lead.pipeline_stage === 'new')) {
                update.pipeline_stage = 'contacted';
                lead.pipeline_stage = 'contacted';
            }
            this.renderLeads();
        }
        await this.api('updateLead', update, 'POST');
        await this.refreshCurrentList();
    }

    async cyclePipelineStage(leadId) {
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;
        const stages = ['new', 'contacted', 'engaged', 'client', 'no_response'];
        const current = lead.pipeline_stage || 'new';
        const idx = stages.indexOf(current);
        const next = stages[(idx + 1) % stages.length];
        lead.pipeline_stage = next;
        this.renderLeads();
        await this.api('updateLead', { id: leadId, pipeline_stage: next }, 'POST');
        await this.refreshCurrentList();
    }

    async incrementFollowUp(leadId) {
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;
        const current = parseInt(lead.follow_up_count) || 0;
        if (current >= 4) return;
        lead.follow_up_count = current + 1;
        this.renderLeads();
        await this.api('updateLead', { id: leadId, follow_up_count: current + 1 }, 'POST');
        await this.refreshCurrentList();
    }

    async decrementFollowUp(leadId) {
        const lead = this.currentLeads.find(l => l.id == leadId);
        if (!lead) return;
        const current = parseInt(lead.follow_up_count) || 0;
        if (current <= 0) return;
        lead.follow_up_count = current - 1;
        this.renderLeads();
        await this.api('updateLead', { id: leadId, follow_up_count: current - 1 }, 'POST');
        await this.refreshCurrentList();
    }

    closeLeadDetail() {
        document.getElementById('slideBackdrop').classList.remove('open');
        document.getElementById('slidePanel').classList.remove('open');
        this.currentLeadDetail = null;
        if (this.slideMap) { this.slideMap.remove(); this.slideMap = null; }
    }

    async trackWebsiteVisitSilent(leadId) {
        await this.api('updateLead', { id: leadId, visited_website: 1 }, 'POST');
    }

    async trackSocialVisitSilent(leadId) {
        await this.api('updateLead', { id: leadId, visited_social: 1 }, 'POST');
    }

    async updateTracking(leadId, field, value) {
        await this.api('updateLead', { id: leadId, [field]: value ? 1 : 0 }, 'POST');
        await this.refreshLeadsOnly();
    }

    async saveNotes() {
        if (!this.currentLeadDetail) return;
        const notes = document.getElementById('slideNotes').value;
        await this.api('updateLead', { id: this.currentLeadDetail.id, notes }, 'POST');
        this.toast('Notes saved');
        await this.refreshCurrentList();
    }

    // ADD LEADS - BULK SCRAPE

    async openAddLeadsModal() {
        // Out of credits — prompt to upgrade instead of opening the search modal.
        if (this.credits < 1) { this.showUpgradePrompt(1); return; }
        document.getElementById('addLeadsModal').classList.add('active');
        this.resetScrapeUI();
        this.updateScrapeLimitOptions();
        await this.loadStatesForCountry(this.selectedCountry);
        this.renderStates();
    }

    // Grey out "Results per City" options that would cost more than the user's
    // remaining credits (1 credit per lead), and nudge them to upgrade.
    updateScrapeLimitOptions() {
        const sel = document.getElementById('scrapeLimit');
        if (!sel) return;
        const credits = this.credits;
        Array.from(sel.options).forEach(opt => {
            if (opt.value === 'all') {
                opt.disabled = credits < 1;
                opt.textContent = `Use all remaining credits (${credits.toLocaleString()})`;
            } else {
                const val = parseInt(opt.value, 10);
                const tooMany = val > credits;
                opt.disabled = tooMany;
                opt.textContent = tooMany ? `${val} — upgrade for more` : String(val);
            }
        });
        // If the current selection is now disabled, pick the largest affordable
        // fixed amount; if none fits (e.g. only a few credits left), fall back to
        // "use all remaining credits".
        if (sel.selectedOptions[0] && sel.selectedOptions[0].disabled) {
            const allowedNumeric = Array.from(sel.options).filter(o => o.value !== 'all' && !o.disabled);
            if (allowedNumeric.length) {
                sel.value = allowedNumeric[allowedNumeric.length - 1].value;
            } else if (credits >= 1) {
                sel.value = 'all';
            }
        }
        this.updateCityCounts();
    }

    // Resolve the per-city results limit from the dropdown ("all" = remaining credits).
    getScrapeLimit() {
        const sel = document.getElementById('scrapeLimit');
        if (!sel) return 0;
        if (sel.value === 'all') return Math.max(1, this.credits);
        return parseInt(sel.value, 10) || 0;
    }

    async loadStatesForCountry(country) {
        if (!this.statesCache[country]) {
            const data = await this.api('getStates', { country });
            if (data.success) this.statesCache[country] = data.states;
        }
        this.states = this.statesCache[country] || [];
    }

    async switchCountry(country) {
        if (country === this.selectedCountry) return;
        this.selectedCountry = country;
        this.states = [];
        this.cities = [];
        this.selectedStates = new Set();
        this.selectedCities = new Set();
        this.citySortOrder = 'name';
        document.querySelectorAll('[data-citysort]').forEach(p => p.classList.toggle('active', p.dataset.citysort === 'name'));
        document.querySelectorAll('.country-pick').forEach(b => b.classList.toggle('active', b.dataset.country === country));
        document.getElementById('statesLabel').textContent = country === 'UK' ? 'Regions' : country === 'EU' ? 'Countries' : 'States';
        await this.loadStatesForCountry(country);
        this.renderStates();
        this.renderCities();
        this.updateCityCounts();
    }

    closeAddLeadsModal() {
        document.getElementById('addLeadsModal').classList.remove('active');
        this.resetScrapeUI();
    }

    resetScrapeUI() {
        document.getElementById('startScrapeBtn').classList.remove('hidden');
        document.getElementById('startScrapeBtn').disabled = false;
        document.getElementById('startScrapeBtn').innerHTML = '<i class="fas fa-rocket"></i> Start Search';
        document.getElementById('cancelScrapeBtn').classList.remove('hidden');
        document.getElementById('cancelScrapeBtn').disabled = false;
        document.getElementById('cancelScrapeBtn').textContent = 'Cancel';
        document.getElementById('closeScrapeBtn').classList.add('hidden');
        document.getElementById('scrapeProgress').classList.add('hidden');
        document.getElementById('aiScrapeSection').style.display = 'none';
        const _al = document.getElementById('scrapeActivityLog');
        if (_al) { _al.style.display = 'none'; _al.innerHTML = ''; }
    }

    showBgProgress() {
        let bar = document.getElementById('bgProgressBar');
        if (!bar) {
            const wrap = document.createElement('div');
            wrap.id = 'bgProgressBar';
            wrap.style.cssText = 'position:fixed;bottom:20px;right:20px;background:var(--card-solid);border:1px solid var(--card-border);border-radius:14px;padding:14px 18px;box-shadow:0 8px 30px rgba(0,0,0,0.12);z-index:9999;min-width:280px;font-size:13px;font-family:inherit;backdrop-filter:blur(20px);';
            wrap.innerHTML = `
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <i class="fas fa-robot" style="color:var(--purple);"></i>
                    <span style="font-weight:600;">${this.scraping ? 'Search in Progress' : 'Enrichment Running'}</span>
                    <button onclick="app.openAddLeadsModal()" style="margin-left:auto;background:none;border:none;color:var(--accent);cursor:pointer;font-size:12px;font-weight:600;">View</button>
                </div>
                <div style="height:4px;background:var(--card-border);border-radius:99px;overflow:hidden;">
                    <div id="bgProgressFill" style="height:100%;background:var(--purple);border-radius:99px;width:0%;transition:width 0.3s;"></div>
                </div>
                <div id="bgProgressText" style="font-size:11px;color:var(--text-secondary);margin-top:6px;">Working...</div>
            `;
            document.body.appendChild(wrap);
        }
    }

    updateBgProgress(pct, text) {
        const fill = document.getElementById('bgProgressFill');
        const textEl = document.getElementById('bgProgressText');
        if (fill) fill.style.width = pct + '%';
        if (textEl) textEl.textContent = text;
    }

    hideBgProgress() {
        const bar = document.getElementById('bgProgressBar');
        if (bar) {
            bar.style.transition = 'opacity 0.3s';
            bar.style.opacity = '0';
            setTimeout(() => bar.remove(), 300);
        }
    }

    renderStates() {
        const list = document.getElementById('statesList');
        const q = (document.getElementById('stateSearch')?.value || '').toLowerCase();
        const filtered = q ? this.states.filter(s => s.state_name.toLowerCase().includes(q)) : this.states;
        list.innerHTML = filtered.map(s => {
            const searched = (this.currentList?.state_stats || []).find(ss => ss.state_name === s.state_name);
            const checked = this.selectedStates.has(s.state_id) ? 'checked' : '';
            return `<label class="selector-item">
                <input type="checkbox" value="${this.esc(s.state_id)}" data-name="${this.esc(s.state_name)}" ${checked} onchange="app.onStateToggle(this)">
                <span>${this.esc(s.state_name)}</span>
                ${searched ? `<span class="searched-badge">${searched.cities_searched} searched</span>` : ''}
            </label>`;
        }).join('');
    }

    filterStates() {
        this.renderStates();
    }

    async onStateToggle(el) {
        if (el.checked) {
            this.selectedStates.add(el.value);
            const data = await this.api('getCities', { state_id: el.value, country: this.selectedCountry });
            if (data.success) {
                const stateName = el.dataset.name;
                data.cities.forEach(c => {
                    c.state_id = el.value;
                    c.state_name = stateName;
                    c.population = c.population ? parseInt(c.population) : null;
                });
                this.cities = [...this.cities.filter(c => c.state_id !== el.value), ...data.cities];
            }
        } else {
            this.selectedStates.delete(el.value);
            this.cities = this.cities.filter(c => c.state_id !== el.value);
            this.cities.forEach(c => { if (c.state_id === el.value) this.selectedCities.delete(`${c.city}|${el.value}`); });
        }
        this.renderCities();
        this.updateCityCounts();
    }

    renderCities() {
        const list = document.getElementById('citiesList');
        const stateFiltered = this.cities.filter(c => this.selectedStates.has(c.state_id));
        if (stateFiltered.length === 0) {
            const label = this.selectedCountry === 'UK' ? 'region' : this.selectedCountry === 'EU' ? 'country' : 'state';
            list.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-tertiary);font-size:13px;">Select a ${label} to see cities</div>`;
            document.getElementById('citiesCount').textContent = '';
            return;
        }
        const q = (document.getElementById('citySearch')?.value || '').toLowerCase();
        let filtered = q ? stateFiltered.filter(c => c.city.toLowerCase().includes(q)) : stateFiltered;

        if (this.cityFilter === 'searched') {
            filtered = filtered.filter(c => this.searchedCitiesForList.has(`${c.city}|${c.state_name}`));
        } else if (this.cityFilter === 'unsearched') {
            filtered = filtered.filter(c => !this.searchedCitiesForList.has(`${c.city}|${c.state_name}`));
        }

        if (this.citySortOrder === 'pop_desc') {
            filtered.sort((a, b) => (b.population || 0) - (a.population || 0));
        } else if (this.citySortOrder === 'pop_asc') {
            filtered.sort((a, b) => (a.population || 0) - (b.population || 0));
        } else {
            filtered.sort((a, b) => a.city.localeCompare(b.city));
        }

        document.getElementById('citiesCount').textContent = `(${filtered.length} of ${stateFiltered.length})`;
        list.innerHTML = filtered.map(c => {
            const key = `${c.city}|${c.state_id}`;
            const searchedKey = `${c.city}|${c.state_name}`;
            const isSearched = this.searchedCitiesForList.has(searchedKey);
            const popStr = c.population ? c.population.toLocaleString() : '—';
            return `<label class="selector-item">
                <input type="checkbox" value="${key}" ${this.selectedCities.has(key) ? 'checked' : ''} onchange="app.onCityToggle(this)">
                <span style="flex:1;">${this.esc(c.city)}</span>
                <span style="font-size:11px;color:var(--text-tertiary);margin-left:auto;min-width:60px;text-align:right;">${popStr}</span>
                ${isSearched ? `<span class="searched-badge">done</span>` : ''}
            </label>`;
        }).join('');
    }

    setCityFilter(filter) {
        this.cityFilter = filter;
        document.querySelectorAll('.city-filter-pill').forEach(p => {
            p.classList.toggle('active', p.dataset.cityfilter === filter);
        });

        const stateFiltered = this.cities.filter(c => this.selectedStates.has(c.state_id));
        const q = (document.getElementById('citySearch')?.value || '').toLowerCase();
        let filtered = q ? stateFiltered.filter(c => c.city.toLowerCase().includes(q)) : stateFiltered;
        if (filter === 'searched') {
            filtered = filtered.filter(c => this.searchedCitiesForList.has(`${c.city}|${c.state_name}`));
        } else if (filter === 'unsearched') {
            filtered = filtered.filter(c => !this.searchedCitiesForList.has(`${c.city}|${c.state_name}`));
        }
        filtered.forEach(c => {
            this.selectedCities.add(`${c.city}|${c.state_id}`);
        });

        this.updateCityCounts();
        this.renderCities();
    }

    setCitySort(order) {
        this.citySortOrder = order;
        document.querySelectorAll('[data-citysort]').forEach(p => {
            p.classList.toggle('active', p.dataset.citysort === order);
        });
        this.renderCities();
    }

    filterCities() {
        this.renderCities();
    }

    onCityToggle(el) {
        if (el.checked) this.selectedCities.add(el.value);
        else this.selectedCities.delete(el.value);
        this.updateCityCounts();
    }

    toggleAllStates(checked) {
        document.querySelectorAll('#statesList input[type="checkbox"]').forEach(cb => {
            if (cb.checked !== checked) { cb.checked = checked; this.onStateToggle(cb); }
        });
    }

    toggleAllCities(checked) {
        document.querySelectorAll('#citiesList input[type="checkbox"]').forEach(cb => {
            cb.checked = checked;
            if (checked) this.selectedCities.add(cb.value);
            else this.selectedCities.delete(cb.value);
        });
        this.updateCityCounts();
    }

    updateCityCounts() {
        const perCity = this.getScrapeLimit();
        document.getElementById('selectedCitiesCount').textContent = this.selectedCities.size;
        // Upper bound of LEADS (cities x results-per-city). Each lead a search
        // returns costs 1 credit; enrichment is free.
        document.getElementById('estimatedCredits').textContent = (this.selectedCities.size * perCity).toLocaleString();
    }

    async startBulkScrape() {
        const query = document.getElementById('scrapeQuery').value.trim();
        if (!query) { this.toast('Enter a search query'); return; }
        if (this.selectedCities.size === 0) { this.toast('Select at least one city'); return; }
        // Each lead a search returns costs 1 credit. Need at least 1 to start.
        if (this.credits < 1) { this.showUpgradePrompt(1); return; }

        this.scraping = true;
        const limit = this.getScrapeLimit();
        const btn = document.getElementById('startScrapeBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
        document.getElementById('closeScrapeBtn').classList.add('hidden');
        document.getElementById('scrapeProgress').classList.remove('hidden');
        document.getElementById('aiScrapeSection').style.display = 'none';
        document.getElementById('scrapeStep1Status').textContent = '';
        document.getElementById('cancelScrapeBtn').textContent = 'Cancel';
        const actLog = document.getElementById('scrapeActivityLog');
        actLog.style.display = 'block';
        actLog.innerHTML = '';

        const _logActivity = (msg, type = 'info') => {
            const colors = { info: 'var(--text-secondary)', success: '#22c55e', warn: '#f59e0b', error: '#ef4444' };
            const line = document.createElement('div');
            line.style.cssText = `padding:2px 0;color:${colors[type] || colors.info};border-bottom:1px solid rgba(255,255,255,0.04);`;
            line.textContent = msg;
            actLog.prepend(line);
        };

        const cityList = [];
        this.selectedCities.forEach(key => {
            const [cityName, stateId] = key.split('|');
            const cityObj = this.cities.find(c => c.city === cityName && c.state_id === stateId);
            if (cityObj) cityList.push(cityObj);
        });

        let completed = 0;
        const total = cityList.length;
        let totalInserted = 0;
        let totalFound = 0;

        const _updateBar = () => {
            const pct = Math.round((completed / total) * 100);
            document.getElementById('scrapeProgressBar').style.width = pct + '%';
            document.getElementById('scrapeProgressText').textContent = `${completed} of ${total} cities (${pct}%)`;
            document.getElementById('scrapeProgressCount').textContent = `${totalFound.toLocaleString()} found · ${totalInserted.toLocaleString()} saved`;
        };

        document.getElementById('scrapeProgressText').textContent = `0 of ${total} cities (0%)`;

        const chunkSize = 3;
        for (let i = 0; i < cityList.length; i += chunkSize) {
            if (!this.scraping) break;
            const chunk = cityList.slice(i, i + chunkSize);

            const promises = chunk.map(async (city) => {
                const fullQuery = `${query} in ${city.city}, ${city.state_name}`;
                _logActivity(`Searching "${query}" in ${city.city}, ${city.state_name}...`);
                try {
                    // Log the search for history/analytics only. Credits are charged
                    // per lead saved, server-side in addLeads below.
                    fetch('log_api_call.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ credits_used: 0, scraper_model: 'google_maps', search_query: fullQuery, input_params: { limit }, status: 'pending' })
                    }).catch(() => {});

                    const res = await fetch(`${this.mapsApiUrl}&term=${encodeURIComponent(query)}&city=${encodeURIComponent(city.city)}&state=${encodeURIComponent(city.state_name)}&limit=${limit}`);
                    const data = await res.json();
                    const results = data?.data || [];

                    totalFound += results.length;
                    completed++;
                    _updateBar();

                    if (results.length > 0) {
                        _logActivity(`Found ${results.length} businesses in ${city.city}`, 'success');

                        const leads = results.map(r => ({
                            ...r,
                            city: city.city,
                            state: city.state_name
                        }));
                        const addRes = await this.api('addLeads', { list_id: this.currentList.id, leads }, 'POST');
                        if (addRes.success) {
                            totalInserted += addRes.inserted;
                            // Server charged 1 credit per lead saved — sync the true balance.
                            if (typeof addRes.credits !== 'undefined') { this.credits = addRes.credits; this.updateCreditsDisplay(); }
                            _logActivity(`+${addRes.inserted} new leads saved from ${city.city}` + (addRes.skipped ? ` (${addRes.skipped} already in list)` : ''), 'success');
                            if (addRes.skipped_no_credit > 0) {
                                this.scraping = false;   // out of credits — stop the rest of the run
                                _logActivity(`Out of credits — some leads in ${city.city} weren't saved. Add credits to continue.`, 'error');
                                this.showUpgradePrompt(1);
                            }
                            _updateBar();
                        }
                    } else {
                        _logActivity(`No results for ${city.city}`, 'warn');
                    }

                    this.api('logSearch', {
                        list_id: this.currentList.id,
                        search_query: query,
                        state_name: city.state_name,
                        city: city.city,
                        results_count: results.length
                    }, 'POST');

                    return results.length;
                } catch (err) {
                    console.error(`Error scraping ${city.city}:`, err);
                    _logActivity(`Error: ${city.city} - ${err.message}`, 'error');
                    completed++;
                    _updateBar();
                    return 0;
                }
            });

            await Promise.all(promises);
            this.loadLeads();
        }

        this.scraping = false;

        await this.refreshCurrentList();
        if (totalInserted === 0 && totalFound > 0) {
            this.toast(`No new leads added — those ${totalFound.toLocaleString()} results are already in this list. Try a new city or a different search.`);
        } else if (this.credits < 1 && totalFound > totalInserted) {
            this.toast(`${totalInserted.toLocaleString()} new leads added — you ran out of credits before saving the rest. Upgrade to continue.`);
        } else {
            this.toast(`${totalInserted.toLocaleString()} new leads added — enrichment starting in background`);
        }

        document.getElementById('addLeadsModal').classList.remove('active');
        this.resetScrapeUI();

        this.fireAllScrapes();
    }

    finishAndClose() {
        document.getElementById('addLeadsModal').classList.remove('active');
        this.resetScrapeUI();
        this.refreshCurrentList();
    }

    async fireAllScrapes() {
        if (!this.currentList) return;
        const listId = this.currentList.id;
        let totalSent = 0, totalWebsites = 0;
        while (true) {
            const res = await this.api('fireAllScrapes', { list_id: listId, batch_size: 100 }, 'POST');
            if (!res.success) return;
            if (typeof res.credits !== 'undefined') { this.credits = res.credits; this.updateCreditsDisplay(); }
            if (res.out_of_credits) { this.toast('Out of credits — enrichment paused. Add credits to finish.'); break; }
            totalWebsites = res.total_with_website || totalWebsites;
            const fired = res.fired || 0;
            totalSent += fired;
            if (fired === 0 && (res.pending || 0) === 0 && (res.processing || 0) === 0) {
                if (totalSent === 0 && (res.completed || 0) + (res.failed || 0) === 0) return;
                break;
            }
            if (fired === 0) break;
            await new Promise(r => setTimeout(r, 300));
        }
        this.enrichmentPollId = listId;
        this._recoveryAttempts = 0;
        this._totalRecoveryAttempts = 0;
        this._enrichLiveLoop(listId);
    }

    async _enrichLiveLoop(listId) {
        if (this.enrichmentPollId !== listId) return;

        const prog = await fetch('leadlists.php?action=getEnrichmentProgress&list_id=' + listId).then(r => r.json()).catch(() => null);
        if (!prog || !prog.success) {
            setTimeout(() => this._enrichLiveLoop(listId), 3000);
            return;
        }

        const { total, pending, processing, completed, failed, needs_enrichment } = prog;

        if (document.getElementById('adminEnrichPanel')) {
            this.updateAdminEnrichPanel(prog);
        }

        if (this.currentList && this.currentList.id == listId) {
            this.loadLeads();
        }

        if ((processing || 0) === 0 && (pending || 0) === 0 && (needs_enrichment || 0) === 0) {
            if ((failed || 0) > 0 && !this._failedRetryDone) {
                this._failedRetryDone = true;
                this.api('retryFailedEnrichments', { list_id: listId }, 'POST').then(r => {
                    if (r && r.success && (r.recovered > 0 || r.retried > 0)) {
                        this._failedRetryDone = false;
                        this.loadLeads();
                        this._enrichLiveLoop(listId);
                    } else {
                        this.enrichmentPollId = null;
                        this.loadLeads();
                    }
                });
                return;
            }
            this._failedRetryDone = false;
            this._recoveryAttempts = 0;
            this.enrichmentPollId = null;
        } else if ((processing || 0) > 0 && (pending || 0) === 0 && (needs_enrichment || 0) === 0) {
            this._recoveryAttempts = (this._recoveryAttempts || 0) + 1;
            this._totalRecoveryAttempts = (this._totalRecoveryAttempts || 0) + 1;
            if (this._totalRecoveryAttempts >= 20) {
                this._totalRecoveryAttempts = 0;
                this._recoveryAttempts = 0;
                this._failedRetryDone = false;
                this.enrichmentPollId = null;
                this.loadLeads();
                return;
            }
            if (this._recoveryAttempts >= 3) {
                this._recoveryAttempts = 0;
                await this.api('recoverStuckEnrichments', { list_id: listId }, 'POST');
                this.loadLeads();
            }
            setTimeout(() => this._enrichLiveLoop(listId), 2000);
        } else {
            this._recoveryAttempts = 0;
            if ((pending || 0) > 0 || (needs_enrichment || 0) > 0) {
                const r = await this.api('fireAllScrapes', { list_id: listId, batch_size: 100 }, 'POST');
                if (r && typeof r.credits !== 'undefined') { this.credits = r.credits; this.updateCreditsDisplay(); }
                if (r && r.out_of_credits) {
                    this.enrichmentPollId = null;   // stop polling; leads stay pending until top-up
                    this.toast('Out of credits — enrichment paused. Add credits to finish.');
                    this.loadLeads();
                    return;
                }
            }
            setTimeout(() => this._enrichLiveLoop(listId), 3000);
        }
    }

    async adminForceReenrich() {
        return this.forceReenrich();
    }

    async forceReenrich() {
        if (!this.currentList) return;
        const listId = this.currentList.id;
        const name = this.currentList.name;
        if (!confirm(`Re-enrich ALL websites in "${name}"?\n\nThis will reset every lead and re-search all websites from scratch. This can take a while for large lists.`)) return;

        const res = await this.api('forceReenrich', { list_id: listId }, 'POST');
        if (!res.success) { this.toast(res.error || 'Failed'); return; }

        const total = res.reset || 0;
        this.toast(`Reset ${total.toLocaleString()} websites — starting re-enrichment...`);

        this.showAdminEnrichPanel(total);
        this.enrichmentPollId = listId;
        this._recoveryAttempts = 0;
        this._totalRecoveryAttempts = 0;
        this._adminEnrichTotal = total;
        this._adminEnrichStart = Date.now();
        await this.loadLeads();
        await this.api('fireAllScrapes', { list_id: listId, batch_size: 100 }, 'POST');
        this._enrichLiveLoop(listId);
    }

    showAdminEnrichPanel(total) {
        let panel = document.getElementById('adminEnrichPanel');
        if (panel) panel.remove();
        panel = document.createElement('div');
        panel.id = 'adminEnrichPanel';
        panel.innerHTML = `
            <div class="aep-header"><i class="fas fa-sync-alt"></i> Re-Enrichment</div>
            <div class="aep-bar"><div class="aep-fill" id="aepFill"></div></div>
            <div class="aep-stats">
                <span><span class="num" id="aepDone">0</span> / <span id="aepTotal">${total.toLocaleString()}</span> done</span>
                <span><span class="num" id="aepEmails">0</span> emails</span>
                <span><span class="num" id="aepSocials">0</span> socials</span>
                <span id="aepTime">starting...</span>
            </div>
        `;
        document.body.appendChild(panel);
    }

    updateAdminEnrichPanel(data) {
        const panel = document.getElementById('adminEnrichPanel');
        if (!panel) return;
        const total = this._adminEnrichTotal || data.total || 1;
        const done = (data.completed || 0) + (data.failed || 0);
        const pct = Math.round((done / total) * 100);
        const fill = document.getElementById('aepFill');
        if (fill) fill.style.width = pct + '%';
        const doneEl = document.getElementById('aepDone');
        if (doneEl) doneEl.textContent = done.toLocaleString();
        const totalEl = document.getElementById('aepTotal');
        if (totalEl) totalEl.textContent = total.toLocaleString();
        const emailsEl = document.getElementById('aepEmails');
        if (emailsEl) emailsEl.textContent = (data.with_emails || 0).toLocaleString();
        const socialsEl = document.getElementById('aepSocials');
        if (socialsEl) socialsEl.textContent = (data.with_socials || 0).toLocaleString();
        const timeEl = document.getElementById('aepTime');
        if (timeEl && this._adminEnrichStart) {
            const elapsed = Math.round((Date.now() - this._adminEnrichStart) / 1000);
            const mins = Math.floor(elapsed / 60);
            const secs = elapsed % 60;
            timeEl.textContent = mins > 0 ? `${mins}m ${secs}s` : `${secs}s`;
        }
        if (done >= total) {
            if (fill) fill.style.background = 'linear-gradient(90deg, #34C759, #30D158)';
            const header = panel.querySelector('.aep-header');
            if (header) header.innerHTML = '<i class="fas fa-check-circle" style="color:#34C759;"></i> Re-Enrichment Complete!';
            setTimeout(() => {
                panel.style.transition = 'opacity 0.5s, transform 0.5s';
                panel.style.opacity = '0';
                panel.style.transform = 'translateX(-50%) translateY(20px)';
                setTimeout(() => panel.remove(), 500);
            }, 5000);
            this._adminEnrichTotal = null;
            this._adminEnrichStart = null;
        }
    }

    async pollEnrichmentProgress(listId, totalFired) {
        if (this.enrichmentPollId !== listId) return;
        this._recoveryAttempts = 0;
        this._enrichLiveLoop(listId);
    }

    updateCreditsDisplay() {
        document.getElementById('creditsDisplay').textContent = this.credits.toLocaleString();
        document.getElementById('creditsDisplay2').textContent = this.credits.toLocaleString();
        // Low-credit upgrade banner (1 credit = 1 lead) — lives in both the
        // lists view and the list-detail view, updated live as credits change.
        const low = this.credits <= 50;
        document.querySelectorAll('.lc-count').forEach(el => { el.textContent = this.credits.toLocaleString(); });
        document.querySelectorAll('.low-credit-banner').forEach(b => { b.style.display = low ? 'flex' : 'none'; });
        if (typeof this.updateScrapeLimitOptions === 'function') this.updateScrapeLimitOptions();
        if (window.parent !== window) {
            window.parent.postMessage({ type: 'creditUpdate', credits: this.credits }, '*');
        }
    }

    // LIST MAP

    initListMap() {
        if (this.listMap) { this.listMap.remove(); this.listMap = null; }
        this.listMapMarkers = [];
        const el = document.getElementById('listMap');
        if (!el) return;
        this.listMap = L.map('listMap', { closePopupOnClick: true }).setView([39.8283, -98.5795], 4);
        const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 }).addTo(this.listMap);
        const sat = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { attribution: '&copy; Esri', maxZoom: 19 });
        L.control.layers({ 'Street': streetLayer, 'Satellite': sat }, null, { position: 'topright' }).addTo(this.listMap);

        this.listMapDrawnItems = new L.FeatureGroup();
        this.listMap.addLayer(this.listMapDrawnItems);
        const drawControl = new L.Control.Draw({
            draw: {
                polygon: { allowIntersection: true, shapeOptions: { color: '#c85719', fillColor: '#c85719', fillOpacity: 0.15, weight: 2 } },
                circle: { shapeOptions: { color: '#c85719', fillColor: '#c85719', fillOpacity: 0.15, weight: 2 } },
                rectangle: { shapeOptions: { color: '#c85719', fillColor: '#c85719', fillOpacity: 0.15, weight: 2 } },
                polyline: false, marker: false, circlemarker: false
            },
            edit: { featureGroup: this.listMapDrawnItems, remove: true }
        });
        this.listMap.addControl(drawControl);
        this.listMap.on(L.Draw.Event.CREATED, (e) => {
            const layer = e.layer;
            this.listMapDrawnItems.addLayer(layer);
            this.selectLeadsInShape(layer);
            setTimeout(() => this.listMapDrawnItems.removeLayer(layer), 500);
        });
    }

    async loadMapLeads() {
        if (!this.currentList) return;
        this.allMapLeads = this.currentLeads || [];
        this.updateListMap();
    }

    selectLeadsInShape(shape) {
        let count = 0;
        if (!this.allMapLeads) return;
        this.allMapLeads.forEach(lead => {
            const lat = parseFloat(lead.latitude), lng = parseFloat(lead.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            const pt = L.latLng(lat, lng);
            let inside = false;
            if (shape instanceof L.Circle) {
                inside = shape.getLatLng().distanceTo(pt) <= shape.getRadius();
            } else if (shape instanceof L.Polygon || shape instanceof L.Rectangle) {
                const poly = shape.getLatLngs()[0];
                let x = pt.lat, y = pt.lng, ii = false;
                for (let i = 0, j = poly.length - 1; i < poly.length; j = i++) {
                    const xi = poly[i].lat, yi = poly[i].lng, xj = poly[j].lat, yj = poly[j].lng;
                    if (((yi > y) !== (yj > y)) && (x < (xj - xi) * (y - yi) / (yj - yi) + xi)) ii = !ii;
                }
                inside = ii;
            }
            if (inside) {
                this.selectedLeads.add(lead.id);
                count++;
            }
        });
        if (count > 0) {
            this.renderLeads();
            this.updateSelectionUI();
            this.toast(`Selected ${count} leads from map`);
        }
    }

    toggleListMap() {
        this.listMapVisible = !this.listMapVisible;
        document.getElementById('listMapContainer').style.display = this.listMapVisible ? 'block' : 'none';
        document.getElementById('listMapToggle').innerHTML = this.listMapVisible ? '<i class="fas fa-chevron-up"></i>' : '<i class="fas fa-chevron-down"></i>';
        if (this.listMapVisible && this.listMap) {
            setTimeout(() => this.listMap.invalidateSize(), 100);
        }
    }

    getFilteredMapLeads() {
        if (!this.allMapLeads) return [];
        let leads = this.allMapLeads;
        if (this.hasFilter) {
            const hf = this.hasFilter;
            leads = leads.filter(lead => {
                if (hf === 'visited') return lead.visited_website == 1;
                if (hf === 'reached_out') return lead.reached_out == 1;
                if (hf === 'phone') return !!(lead.phone && lead.phone.trim());
                if (hf === 'email') return (lead.emails || []).length > 0;
                if (hf === 'socials') return (lead.social_media_links || []).length > 0;
                if (hf === 'notes') return !!(lead.notes && lead.notes.trim());
                if (hf === 'selected') return this.selectedLeads.has(lead.id);
                return true;
            });
        }
        if (this.searchQuery) {
            const q = this.searchQuery.toLowerCase().trim();
            leads = leads.filter(lead => {
                const name = (lead.business_name || '').toLowerCase();
                const addr = (lead.full_address || lead.address || '').toLowerCase();
                const phone = (lead.phone || '').toLowerCase();
                const notes = (lead.notes || '').toLowerCase();
                return name.includes(q) || addr.includes(q) || phone.includes(q) || notes.includes(q);
            });
        }
        return leads;
    }

    updateListMap() {
        if (!this.listMap || !this.allMapLeads) return;
        this.listMapMarkers.forEach(m => this.listMap.removeLayer(m));
        this.listMapMarkers = [];
        const withCoords = this.getFilteredMapLeads().filter(lead => {
            const lat = parseFloat(lead.latitude), lng = parseFloat(lead.longitude);
            return Number.isFinite(lat) && Number.isFinite(lng);
        });
        document.getElementById('listMapCount').textContent = withCoords.length + ' locations';
        if (withCoords.length === 0) return;
        const bounds = L.latLngBounds();
        withCoords.forEach(lead => {
            const lat = parseFloat(lead.latitude), lng = parseFloat(lead.longitude);
            const emails = lead.emails || [];
            const socials = lead.social_media_links || [];
            const isSelected = this.selectedLeads.has(lead.id);
            const stars = lead.rating ? '★'.repeat(Math.round(lead.rating)) + '☆'.repeat(5 - Math.round(lead.rating)) : '';

            let popup = `<div style="min-width:260px;max-width:300px;font-family:Inter,system-ui,sans-serif;">`;
            popup += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding-bottom:8px;border-bottom:1px solid #eee;">`;
            popup += `<input type="checkbox" ${isSelected ? 'checked' : ''} onchange="app.toggleLeadSelect(${lead.id}, this.checked)" style="width:16px;height:16px;cursor:pointer;accent-color:#c85719;flex-shrink:0;">`;
            popup += `<div><strong style="font-size:14px;color:#1d1d1f;line-height:1.3;">${this.esc(lead.business_name || 'Unknown')}</strong>`;
            if (lead.types) popup += `<div style="font-size:11px;color:#aeaeb2;margin-top:1px;">${this.esc(lead.types).substring(0,60)}</div>`;
            popup += `</div></div>`;

            if (lead.full_address) popup += `<div style="display:flex;align-items:flex-start;gap:6px;font-size:12px;color:#6e6e73;margin-bottom:5px;"><i class="fas fa-map-marker-alt" style="color:#FF3B30;margin-top:2px;font-size:11px;flex-shrink:0;"></i>${this.esc(lead.full_address)}</div>`;
            if (lead.phone) popup += `<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:5px;"><i class="fas fa-phone" style="color:#34C759;font-size:11px;"></i><a href="tel:${lead.phone}" style="color:#1d1d1f;text-decoration:none;">${lead.phone}</a></div>`;
            if (emails.length > 0) popup += `<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:5px;"><i class="fas fa-envelope" style="color:#c85719;font-size:11px;"></i><a href="#" onclick="event.preventDefault();app.sendTemplateEmail('${this.esc(emails[0])}', ${lead.id})" style="color:#c85719;text-decoration:none;">${emails[0]}</a></div>`;
            if (lead.website) popup += `<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:5px;"><i class="fas fa-globe" style="color:#1460a6;font-size:11px;"></i><a href="${lead.website}" target="_blank" style="color:#c85719;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:200px;display:inline-block;">${(lead.website || '').replace(/^https?:\/\//, '')}</a></div>`;

            if (stars) popup += `<div style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:5px;"><span style="color:#ca942a;font-size:13px;letter-spacing:-1px;">${stars}</span><span style="color:#aeaeb2;">${lead.rating} (${lead.review_count || 0})</span></div>`;

            if (socials.length > 0) {
                popup += `<div style="display:flex;gap:8px;margin-top:6px;padding-top:6px;border-top:1px solid #eee;">`;
                socials.slice(0, 5).forEach(url => {
                    const p = SOCIAL_PLATFORMS.find(pl => pl.patterns.some(pat => url.toLowerCase().includes(pat)));
                    popup += `<a href="${url}" target="_blank" style="color:#6e6e73;font-size:14px;" title="${url}"><i class="${p ? p.icon : 'fas fa-link'}"></i></a>`;
                });
                popup += `</div>`;
            }

            const badges = [];
            if (lead.visited_website == 1) badges.push('<span style="background:#fce8dc;color:#c85719;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">Visited</span>');
            if (lead.reached_out == 1) badges.push('<span style="background:#E5F8ED;color:#34C759;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;">Reached Out</span>');
            if (badges.length) popup += `<div style="display:flex;gap:4px;margin-top:6px;">${badges.join('')}</div>`;

            popup += `</div>`;

            const marker = L.marker([lat, lng]).addTo(this.listMap);
            marker.bindPopup(popup, { maxWidth: 320 });
            this.listMapMarkers.push(marker);
            bounds.extend([lat, lng]);
        });
        this.listMap.fitBounds(bounds, { padding: [40, 40] });
    }

    // EXPORT

    toggleExportMenu() {
        document.getElementById('exportMenu').classList.toggle('hidden');
    }

    async handleImportCSV(input) {
        const file = input.files && input.files[0];
        input.value = '';
        if (!file) return;
        if (!this.currentList) { alert('Open a folder first.'); return; }

        this._pendingImport = { file, headers: null, previewRows: [], totalRows: 0 };
        this._importRunning = false;
        this._importCancelled = false;
        document.getElementById('importPreviewModal').classList.add('active');
        document.getElementById('importPreviewTitle').textContent = 'Import CSV Preview';
        document.getElementById('importPreviewSummary').innerHTML = `<div style="text-align:center;padding:24px;color:var(--text-secondary);"><i class="fas fa-spinner fa-spin" style="color:var(--accent);"></i> Reading ${this.esc(file.name)}...</div>`;
        document.getElementById('importPreviewTable').innerHTML = '';
        document.getElementById('importProgressWrap').classList.remove('hidden');
        document.getElementById('startImportBtn').disabled = true;
        document.getElementById('startImportBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reading...';
        this.updateImportProgress(0, 0, 0, 0, 0, 'Reading CSV...');

        try {
            await this.parseCSVFile(file, async (row) => {
                if (!this._pendingImport) throw new Error('Preview cancelled');
                if (!this._pendingImport.headers) {
                    this._pendingImport.headers = row;
                    if (this._pendingImport.headers[0]) this._pendingImport.headers[0] = this._pendingImport.headers[0].replace(/^\uFEFF/, '');
                    return;
                }
                this._pendingImport.totalRows++;
                if (this._pendingImport.previewRows.length < 100) this._pendingImport.previewRows.push(row);
                if (this._pendingImport.totalRows % 5000 === 0) {
                    this.updateImportProgress(0, 0, 0, 0, 0, `Reading ${this._pendingImport.totalRows.toLocaleString()} rows...`);
                    await new Promise(resolve => setTimeout(resolve, 0));
                }
            });
            if (!this._pendingImport.headers) throw new Error('Missing header row');
            this.renderImportPreview();
        } catch (e) {
            if (!this._pendingImport) return;
            document.getElementById('importPreviewSummary').innerHTML = `<div style="color:var(--red);padding:16px;background:#fff5f5;border:1px solid #ffd6d6;border-radius:var(--radius-xs);">Import preview failed: ${this.esc(e.message)}</div>`;
            document.getElementById('importProgressWrap').classList.add('hidden');
            document.getElementById('startImportBtn').disabled = true;
            document.getElementById('startImportBtn').innerHTML = '<i class="fas fa-upload"></i> Start Import';
        }
    }

    renderImportPreview() {
        const pending = this._pendingImport;
        if (!pending) return;
        const shown = pending.previewRows.length;
        document.getElementById('importPreviewTitle').textContent = `Import ${pending.file.name}`;
        document.getElementById('importPreviewSummary').innerHTML = `
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
                <div style="background:var(--bg);border:1px solid var(--card-border);border-radius:var(--radius-xs);padding:14px;">
                    <div style="font-size:11px;color:var(--text-tertiary);font-weight:700;text-transform:uppercase;">File</div>
                    <div style="font-size:14px;font-weight:700;margin-top:4px;word-break:break-all;">${this.esc(pending.file.name)}</div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--card-border);border-radius:var(--radius-xs);padding:14px;">
                    <div style="font-size:11px;color:var(--text-tertiary);font-weight:700;text-transform:uppercase;">Rows Found</div>
                    <div style="font-size:22px;font-weight:800;margin-top:2px;">${pending.totalRows.toLocaleString()}</div>
                </div>
                <div style="background:var(--bg);border:1px solid var(--card-border);border-radius:var(--radius-xs);padding:14px;">
                    <div style="font-size:11px;color:var(--text-tertiary);font-weight:700;text-transform:uppercase;">Import Into</div>
                    <div style="font-size:14px;font-weight:700;margin-top:4px;">${this.esc(this.currentList.name)}</div>
                </div>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);margin-top:12px;">Showing the first ${shown.toLocaleString()} rows with all columns. The import will process all ${pending.totalRows.toLocaleString()} rows.</div>
        `;

        const headers = pending.headers || [];
        const headerHtml = headers.map(h => `<th style="padding:8px;text-align:left;border-bottom:1px solid var(--card-border);background:var(--bg);position:sticky;top:0;white-space:nowrap;">${this.esc(h)}</th>`).join('');
        const rowsHtml = pending.previewRows.map(row => `<tr>${headers.map((h, i) => `<td style="padding:7px 8px;border-bottom:1px solid var(--card-border);font-size:12px;white-space:nowrap;max-width:240px;overflow:hidden;text-overflow:ellipsis;">${this.esc(row[i] || '')}</td>`).join('')}</tr>`).join('');
        document.getElementById('importPreviewTable').innerHTML = `<table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr>${headerHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>`;
        document.getElementById('importProgressWrap').classList.add('hidden');
        document.getElementById('startImportBtn').disabled = pending.totalRows === 0;
        document.getElementById('startImportBtn').innerHTML = `<i class="fas fa-upload"></i> Import ${pending.totalRows.toLocaleString()} Rows`;
    }

    async startImportCSV() {
        const pending = this._pendingImport;
        if (!pending || !pending.file || !pending.headers) return;
        const batchSize = 100;
        let batch = [];
        let processed = 0;
        let inserted = 0;
        let skipped = 0;
        let errors = 0;
        this._importRunning = true;
        this._importCancelled = false;
        document.getElementById('importProgressWrap').classList.remove('hidden');
        document.getElementById('startImportBtn').disabled = true;
        document.getElementById('startImportBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
        document.getElementById('cancelImportBtn').textContent = 'Cancel Import';
        this.updateImportProgress(0, pending.totalRows, 0, 0, 0, 'Starting import...');

        const sendBatch = async () => {
            if (!batch.length) return;
            if (this._importCancelled) throw new Error('Import cancelled');
            const rows = batch;
            batch = [];
            let res = null;
            for (let attempt = 1; attempt <= 3; attempt++) {
                res = await this.api('importCSVBatch', { list_id: this.currentList.id, headers: pending.headers, rows }, 'POST');
                if (res.success) break;
                if (attempt === 3) break;
                this.updateImportProgress(processed, pending.totalRows, inserted, skipped, errors, `Batch failed, retrying ${attempt}/3...`);
                await new Promise(resolve => setTimeout(resolve, attempt * 1000));
            }
            if (!res.success) throw new Error(res.error || 'Import batch failed');
            inserted += res.inserted || 0;
            skipped += res.skipped || 0;
            errors += res.errors || 0;
            this.updateImportProgress(processed, pending.totalRows, inserted, skipped, errors, 'Importing CSV...');
        };

        try {
            let passedHeader = false;
            await this.parseCSVFile(pending.file, async (row) => {
                if (!passedHeader) {
                    passedHeader = true;
                    return;
                }
                batch.push(row);
                processed++;
                if (batch.length >= batchSize) {
                    await sendBatch();
                    await new Promise(resolve => setTimeout(resolve, 0));
                }
            });
            await sendBatch();
            this.updateImportProgress(processed, pending.totalRows, inserted, skipped, errors, 'Import complete');
            document.getElementById('startImportBtn').innerHTML = '<i class="fas fa-check"></i> Complete';
            document.getElementById('cancelImportBtn').textContent = 'Close';
            await this.openList(this.currentList.id);
        } catch (e) {
            this.updateImportProgress(processed, pending.totalRows, inserted, skipped, errors, e.message);
            document.getElementById('startImportBtn').disabled = false;
            document.getElementById('startImportBtn').innerHTML = '<i class="fas fa-upload"></i> Retry Import';
            document.getElementById('cancelImportBtn').textContent = 'Close';
        } finally {
            this._importRunning = false;
        }
    }

    updateImportProgress(processed, total, inserted, skipped, errors, text) {
        const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
        document.getElementById('importProgressBar').style.width = pct + '%';
        document.getElementById('importProgressText').textContent = text;
        document.getElementById('importProgressCount').textContent = total > 0
            ? `${processed.toLocaleString()} / ${total.toLocaleString()} rows (${pct}%) | Inserted ${inserted.toLocaleString()} | Skipped ${skipped.toLocaleString()} | Errors ${errors.toLocaleString()}`
            : '';
    }

    closeImportPreview() {
        if (this._importRunning) {
            this._importCancelled = true;
            document.getElementById('cancelImportBtn').textContent = 'Cancelling...';
            return;
        }
        document.getElementById('importPreviewModal').classList.remove('active');
        this._pendingImport = null;
        this._importCancelled = false;
        document.getElementById('cancelImportBtn').textContent = 'Cancel';
    }

    async parseCSVFile(file, onRow) {
        const reader = file.stream().getReader();
        const decoder = new TextDecoder();
        let row = [];
        let field = '';
        let inQuotes = false;
        let pendingQuote = false;

        const pushRow = async () => {
            if (field !== '' || row.length) {
                row.push(field);
                await onRow(row);
            }
            row = [];
            field = '';
        };

        const processText = async (text) => {
            for (let i = 0; i < text.length; i++) {
                const ch = text[i];
                if (pendingQuote) {
                    pendingQuote = false;
                    if (ch === '"') {
                        field += '"';
                        continue;
                    }
                    inQuotes = false;
                }
                if (inQuotes) {
                    if (ch === '"') {
                        if (i === text.length - 1) {
                            pendingQuote = true;
                        } else if (text[i + 1] === '"') {
                            field += '"';
                            i++;
                        } else {
                            inQuotes = false;
                        }
                    } else {
                        field += ch;
                    }
                } else if (ch === '"') {
                    inQuotes = true;
                } else if (ch === ',') {
                    row.push(field);
                    field = '';
                } else if (ch === '\n') {
                    await pushRow();
                } else if (ch !== '\r') {
                    field += ch;
                }
            }
        };

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            await processText(decoder.decode(value, { stream: true }));
        }
        const rest = decoder.decode();
        if (rest) await processText(rest);
        if (pendingQuote) {
            pendingQuote = false;
            inQuotes = false;
        }
        await pushRow();
    }

    async openExportPreview(type) {
        document.getElementById('exportMenu').classList.add('hidden');
        if (!this.currentList) return;

        this.exportType = type;
        this._csvFilters = { status: '', has: '', pipeline: '', search: '' };
        const modal = document.getElementById('exportPreviewModal');
        const title = document.getElementById('exportPreviewTitle');
        const summary = document.getElementById('exportPreviewSummary');
        const table = document.getElementById('exportPreviewTable');
        const actions = document.getElementById('exportPreviewActions');

        title.textContent = 'Export CSV';
        table.innerHTML = '';
        actions.innerHTML = '';

        const selectStyle = 'height:36px;border:1px solid var(--input-border);border-radius:8px;padding:0 10px;font-size:12px;font-family:inherit;background:#fff;min-width:0;flex:1;';
        summary.innerHTML = `
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                <select id="csvFilterStatus" onchange="app.updateExportPreview()" style="${selectStyle}">
                    <option value="">All Statuses</option>
                    <option value="cold">Cold</option>
                    <option value="warm">Warm</option>
                    <option value="hot">Hot</option>
                </select>
                <select id="csvFilterHas" onchange="app.updateExportPreview()" style="${selectStyle}">
                    <option value="">All Leads</option>
                    <option value="email">Has Email</option>
                    <option value="phone">Has Phone</option>
                    <option value="has_website">Has Website</option>
                    <option value="socials">Has Socials</option>
                    <option value="notes">Has Notes</option>
                    <option value="visited">Visited Website</option>
                    <option value="reached_out">Reached Out</option>
                    <option value="emailed">Emailed</option>
                    <option value="ig_dm">IG DM'd</option>
                </select>
                <select id="csvFilterPipeline" onchange="app.updateExportPreview()" style="${selectStyle}">
                    <option value="">All Stages</option>
                    <option value="new">New</option>
                    <option value="contacted">Contacted</option>
                    <option value="engaged">Engaged</option>
                    <option value="client">Client</option>
                    <option value="no_response">No Response</option>
                </select>
            </div>
            <div id="csvExportStats" style="text-align:center;padding:12px;"><i class="fas fa-spinner fa-spin" style="color:var(--accent);"></i></div>
        `;
        modal.classList.add('active');
        await this.updateExportPreview();
    }

    async updateExportPreview() {
        const statsEl = document.getElementById('csvExportStats');
        const table = document.getElementById('exportPreviewTable');
        const actions = document.getElementById('exportPreviewActions');
        if (!statsEl) return;

        const status = document.getElementById('csvFilterStatus')?.value || '';
        const has = document.getElementById('csvFilterHas')?.value || '';
        const pipeline = document.getElementById('csvFilterPipeline')?.value || '';
        this._csvFilters = { status, has, pipeline, search: '' };

        statsEl.innerHTML = '<div style="text-align:center;padding:8px;"><i class="fas fa-spinner fa-spin" style="color:var(--accent);"></i></div>';
        table.innerHTML = '';
        actions.innerHTML = '';

        const params = { list_id: this.currentList.id };
        if (status) params.status = status;
        if (has) params.has = has;
        if (pipeline) params.pipeline = pipeline;

        const [countData, previewData] = await Promise.all([
            this.api('exportCount', params),
            this.api('exportLeads', { ...params, page: 1, per_page: 10 })
        ]);

        if (!countData.success || countData.total === 0) {
            statsEl.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-secondary);"><i class="fas fa-info-circle" style="font-size:20px;margin-bottom:6px;display:block;"></i>No leads match these filters.</div>';
            actions.innerHTML = '<button class="btn btn-secondary" onclick="app.closeExportPreview()">Close</button>';
            return;
        }

        const t = countData;
        const big = t.total > 500000;
        statsEl.innerHTML = `
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                <div style="flex:1;min-width:120px;padding:12px;background:var(--bg);border-radius:8px;border:1px solid var(--card-border);text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:var(--accent);">${t.total.toLocaleString()}</div>
                    <div style="font-size:11px;color:var(--text-secondary);">Total Leads</div>
                </div>
                <div style="flex:1;min-width:120px;padding:12px;background:var(--bg);border-radius:8px;border:1px solid var(--card-border);text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:var(--green);">${t.with_email.toLocaleString()}</div>
                    <div style="font-size:11px;color:var(--text-secondary);">With Email</div>
                </div>
                <div style="flex:1;min-width:120px;padding:12px;background:var(--bg);border-radius:8px;border:1px solid var(--card-border);text-align:center;">
                    <div style="font-size:22px;font-weight:700;color:var(--blue);">${t.with_phone.toLocaleString()}</div>
                    <div style="font-size:11px;color:var(--text-secondary);">With Phone</div>
                </div>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;font-size:11px;margin-bottom:6px;">
                <span style="padding:2px 8px;background:#c8571910;border-radius:99px;">Cold: ${t.cold.toLocaleString()}</span>
                <span style="padding:2px 8px;background:#ca942a10;border-radius:99px;">Warm: ${t.warm.toLocaleString()}</span>
                <span style="padding:2px 8px;background:#FF2D5510;border-radius:99px;">Hot: ${t.hot.toLocaleString()}</span>
                <span style="padding:2px 8px;background:#34C75910;border-radius:99px;">Socials: ${t.with_socials.toLocaleString()}</span>
                <span style="padding:2px 8px;background:#337f8310;border-radius:99px;">Website: ${t.with_website.toLocaleString()}</span>
            </div>
            ${big ? '<div style="font-size:11px;padding:6px 10px;background:#FEF3C7;border-radius:6px;color:#92400E;margin-top:6px;"><i class="fas fa-info-circle"></i> Large export — file will download as a ZIP with multiple CSVs.</div>' : ''}
        `;

        if (previewData.success && previewData.leads.length > 0) {
            const preview = previewData.leads.slice(0, 10);
            table.innerHTML = `<table style="width:100%;font-size:12px;border-collapse:collapse;"><thead><tr style="background:var(--bg);position:sticky;top:0;">
                <th style="padding:8px;text-align:left;border-bottom:1px solid var(--card-border);">Business</th>
                <th style="padding:8px;text-align:left;border-bottom:1px solid var(--card-border);">City</th>
                <th style="padding:8px;text-align:left;border-bottom:1px solid var(--card-border);">Phone</th>
                <th style="padding:8px;text-align:left;border-bottom:1px solid var(--card-border);">Status</th>
            </tr></thead><tbody>${preview.map(l => `<tr>
                <td style="padding:6px 8px;border-bottom:1px solid var(--card-border);">${this.esc(l.business_name || '')}</td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--card-border);">${this.esc(l.city || '')}</td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--card-border);">${this.esc(l.phone || '—')}</td>
                <td style="padding:6px 8px;border-bottom:1px solid var(--card-border);"><span class="status-badge ${l.status}">${l.status}</span></td>
            </tr>`).join('')}
            ${t.total > 10 ? `<tr><td colspan="4" style="padding:6px;text-align:center;color:var(--text-tertiary);font-style:italic;font-size:11px;">...and ${(t.total - 10).toLocaleString()} more</td></tr>` : ''}</tbody></table>`;
        }

        actions.innerHTML = `
            <button class="btn btn-secondary" onclick="app.closeExportPreview()">Cancel</button>
            <button class="btn btn-primary" onclick="app.downloadServerCSV()"><i class="fas fa-file-csv"></i> Download CSV (${t.total.toLocaleString()} leads)</button>
        `;
    }

    closeExportPreview() {
        document.getElementById('exportPreviewModal').classList.remove('active');
    }

    downloadServerCSV() {
        if (!this.currentList) return;
        const f = this._csvFilters || {};
        const params = new URLSearchParams({ action: 'exportCSV', list_id: this.currentList.id });
        if (f.status) params.set('status', f.status);
        if (f.has) params.set('has', f.has);
        if (f.pipeline) params.set('pipeline', f.pipeline);
        if (f.search) params.set('search', f.search);
        this.closeExportPreview();
        this.toast('Starting download...');
        window.location.href = 'leadlists.php?' + params.toString();
    }

    // GHL EXPORT

    async openGHLExport(preSelectedLeads) {
        if (!this.currentList) return;
        document.getElementById('exportMenu')?.classList.add('hidden');
        const overlay = document.getElementById('ghlExportOverlay');
        overlay.classList.add('active');

        this._ghlLeads = [];
        this._ghlFiltered = [];
        this._ghlSelected = new Set();
        this._ghlTags = [];
        this._ghlSelectedTags = [];
        this._ghlWorkflows = [];
        this._ghlConnections = [];
        this._ghlActiveConn = null;
        this._ghlPreSelected = preSelectedLeads;

        document.getElementById('ghlSubtitle').textContent = this.currentList.name;

        const res = await this.api('getGHLConnections');
        this._ghlConnections = res.connections || [];

        if (this._ghlConnections.length === 0) {
            // First-timer: gate on whether they've claimed the Free CRM yet.
            this.showGHLScreen('ghlGateScreen');
            this.updateGHLBadge(false);
        } else {
            this._ghlActiveConn = this._ghlConnections[0];
            this.updateGHLBadge(true);
            await this.loadGHLExportData(preSelectedLeads);
        }
    }

    showGHLScreen(id) {
        ['ghlGateScreen','ghlSetupScreen','ghlEditorScreen','ghlImportingScreen','ghlDoneScreen'].forEach(s => {
            const el = document.getElementById(s);
            if (el) el.classList.toggle('hidden', s !== id);
        });
    }
    ghlGateYes() {
        // They have the Free CRM — continue to the connection setup.
        this.showGHLScreen('ghlSetupScreen');
        this.updateGHLBadge(false);
        this.renderGHLConnectionsList();
    }
    ghlGateNo() {
        // No Free CRM yet — send them to claim it first.
        this.closeGHLExport();
        (window.top || window).location.href = 'dashboard.php?section=freecrm';
    }

    updateGHLBadge(connected) {
        const b = document.getElementById('ghlConnectionBadge');
        if (connected && this._ghlActiveConn) {
            b.innerHTML = `<i class="fas fa-check-circle"></i> ${this.esc(this._ghlActiveConn.name)}`;
            b.style.cssText = 'background:#DCFCE7;color:#166534;font-size:11px;padding:4px 10px;border-radius:99px;font-weight:600;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
        } else if (connected) {
            b.innerHTML = '<i class="fas fa-check-circle"></i> Connected';
            b.style.cssText = 'background:#DCFCE7;color:#166534;font-size:11px;padding:4px 10px;border-radius:99px;font-weight:600;';
        } else {
            b.innerHTML = '<i class="fas fa-times-circle"></i> Not Connected';
            b.style.cssText = 'background:#FEE2E2;color:#991B1B;font-size:11px;padding:4px 10px;border-radius:99px;font-weight:600;';
        }
    }

    renderGHLConnectionsList() {
        const container = document.getElementById('ghlConnectionsList');
        if (!container) return;
        if (this._ghlConnections.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-tertiary);font-size:13px;"><i class="fas fa-unlink" style="font-size:20px;display:block;margin-bottom:8px;"></i>No connections yet</div>';
            return;
        }
        container.innerHTML = this._ghlConnections.map(c => `
            <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--bg);border:1px solid var(--card-border);border-radius:10px;margin-bottom:8px;cursor:pointer;transition:border-color 0.15s;" onclick="app.selectGHLConnection(${c.id})" onmouseenter="this.style.borderColor='var(--accent)'" onmouseleave="this.style.borderColor='var(--card-border)'">
                <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#FF6B35,#FF3B30);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-bolt" style="color:#fff;font-size:16px;"></i>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${this.esc(c.name)}</div>
                    <div style="font-size:11px;color:var(--text-tertiary);">Location: ${this.esc(c.location_id)}</div>
                </div>
                <div style="display:flex;gap:4px;">
                    <button class="btn-icon" onclick="event.stopPropagation();app.editGHLConnection(${c.id})" title="Edit" style="font-size:12px;"><i class="fas fa-pen"></i></button>
                    <button class="btn-icon" onclick="event.stopPropagation();app.deleteGHLConnection(${c.id})" title="Delete" style="font-size:12px;color:var(--red);"><i class="fas fa-trash"></i></button>
                </div>
                <i class="fas fa-chevron-right" style="color:var(--text-tertiary);font-size:12px;"></i>
            </div>`).join('');
    }

    async selectGHLConnection(connId) {
        this._ghlActiveConn = this._ghlConnections.find(c => c.id == connId);
        this.updateGHLBadge(true);
        await this.loadGHLExportData(this._ghlPreSelected);
    }

    async editGHLConnection(connId) {
        const conn = this._ghlConnections.find(c => c.id == connId);
        if (!conn) return;
        const name = prompt('Connection name:', conn.name);
        if (!name) return;
        const loc = prompt('Location ID:', conn.location_id);
        if (!loc) return;
        const key = prompt('API Key (leave blank to keep current):');
        await this.api('saveGHLConnection', {
            connection_id: connId,
            name,
            api_key: key || conn._apiKey || 'KEEP_EXISTING',
            location_id: loc
        }, 'POST');
        const res = await this.api('getGHLConnections');
        this._ghlConnections = res.connections || [];
        this.renderGHLConnectionsList();
        this.renderGHLSettingsConnections();
        this.toast('Connection updated');
    }

    async deleteGHLConnection(connId) {
        if (!confirm('Delete this connection? This cannot be undone.')) return;
        await this.api('deleteGHLConnection', { connection_id: connId }, 'POST');
        const res = await this.api('getGHLConnections');
        this._ghlConnections = res.connections || [];
        if (this._ghlActiveConn?.id == connId) this._ghlActiveConn = this._ghlConnections[0] || null;
        this.renderGHLConnectionsList();
        this.renderGHLSettingsConnections();
        this.updateGHLBadge(!!this._ghlActiveConn);
        if (this._ghlConnections.length === 0) this.showGHLScreen('ghlSetupScreen');
        this.toast('Connection deleted');
    }

    async connectGHL() {
        const name = document.getElementById('ghlSetupName').value.trim();
        const key = document.getElementById('ghlSetupKey').value.trim();
        const loc = document.getElementById('ghlSetupLocation').value.trim();
        if (!name) { this.toast('Give this connection a name'); return; }
        if (!key || !loc) { this.toast('API Key and Location ID are required'); return; }

        const saveRes = await this.api('saveGHLConnection', { name, api_key: key, location_id: loc }, 'POST');
        if (!saveRes.success) { this.toast('Failed to save: ' + (saveRes.error || '')); return; }

        const test = await this.api('ghlProxy', { method: 'GET', endpoint: `/locations/${loc}/tags`, connection_id: saveRes.connection_id }, 'POST');
        if (!test.success) {
            await this.api('deleteGHLConnection', { connection_id: saveRes.connection_id }, 'POST');
            this.toast('Connection failed — check your API key and Location ID');
            return;
        }

        const res = await this.api('getGHLConnections');
        this._ghlConnections = res.connections || [];
        this._ghlActiveConn = this._ghlConnections.find(c => c.id == saveRes.connection_id) || this._ghlConnections[0];

        document.getElementById('ghlSetupName').value = '';
        document.getElementById('ghlSetupKey').value = '';
        document.getElementById('ghlSetupLocation').value = '';
        document.getElementById('ghlAddConnectionForm').classList.add('hidden');

        this.updateGHLBadge(true);
        this.toast('Connected to your Free CRM!');
        await this.loadGHLExportData(this._ghlPreSelected);
    }

    async switchGHLConnection() {
        const picker = document.getElementById('ghlConnectionPicker');
        const connId = picker.value;
        this._ghlActiveConn = this._ghlConnections.find(c => c.id == connId) || null;
        this.updateGHLBadge(!!this._ghlActiveConn);
        if (this._ghlActiveConn) {
            this._ghlTags = [];
            this._ghlSelectedTags = [];
            this._ghlWorkflows = [];
            this.renderGHLTagChips();
            await this.loadGHLTagsAndWorkflows();
        }
    }

    async loadGHLTagsAndWorkflows() {
        if (!this._ghlActiveConn) return;
        const connId = this._ghlActiveConn.id;
        const locId = this._ghlActiveConn.location_id;

        let tagsData, workflowsData;
        for (let attempt = 0; attempt < 2; attempt++) {
            [tagsData, workflowsData] = await Promise.all([
                this.api('ghlProxy', { method: 'GET', endpoint: `/locations/${locId}/tags`, connection_id: connId }, 'POST'),
                this.api('ghlProxy', { method: 'GET', endpoint: `/workflows/?locationId=${locId}`, connection_id: connId }, 'POST')
            ]);
            if (tagsData?.success && tagsData?.data?.tags) break;
            if (attempt === 0) await new Promise(r => setTimeout(r, 1000));
        }

        const rawTags = tagsData?.data?.tags || tagsData?.data?.Tags || tagsData?.tags || [];
        this._ghlTags = rawTags.map(t => String(typeof t === 'object' ? (t.name ?? t.Name ?? '') : t)).filter(t => t.trim() !== '');
        const rawWorkflows = workflowsData?.data?.workflows || workflowsData?.data?.Workflows || [];
        this._ghlWorkflows = rawWorkflows;

        if (!this._ghlTags.length && tagsData && !tagsData.success) {
            this.toast('Could not load tags — check your Free CRM connection');
        }

        const wfSelect = document.getElementById('ghlWorkflowSelect');
        wfSelect.innerHTML = '<option value="">No workflow</option>' +
            this._ghlWorkflows.map(w => `<option value="${w.id}">${this.esc(w.name)}</option>`).join('');
    }

    renderConnectionPicker() {
        const picker = document.getElementById('ghlConnectionPicker');
        if (!picker) return;
        picker.innerHTML = this._ghlConnections.map(c =>
            `<option value="${c.id}" ${this._ghlActiveConn?.id == c.id ? 'selected' : ''}>${this.esc(c.name)}</option>`
        ).join('');
    }

    async loadGHLExportData(preSelectedLeads) {
        this.showGHLScreen('ghlEditorScreen');
        this.renderConnectionPicker();
        document.getElementById('ghlSpreadsheetBody').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

        if (!this._ghlActiveConn) { this.toast('No connection selected'); return; }
        const connId = this._ghlActiveConn.id;
        const locId = this._ghlActiveConn.location_id;

        this._ghlPage = 1;
        this._ghlPerPage = 50;
        this._ghlTotalLeads = 0;
        this._ghlTotalPages = 1;

        const [leadsData, tagsWorkflows] = await Promise.all([
            this.api('exportLeads', { list_id: this.currentList.id, page: 1, per_page: this._ghlPerPage }),
            this._loadGHLTagsWorkflows(connId, locId)
        ]);

        if (!leadsData.success) { this.toast('Failed to load leads'); return; }

        this._ghlTotalLeads = leadsData.total || 0;
        this._ghlTotalPages = leadsData.total_pages || 1;
        this._ghlLeads = this._mapLeadsToGHL(leadsData.leads);
        this._ghlSelected = new Set(this._ghlLeads.map((_, i) => i));

        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    _mapLeadsToGHL(leads) {
        return leads.map(l => {
            const emails = l.emails || [];
            return {
                _leadId: l.id, firstName: '', lastName: '',
                companyName: l.business_name || '', email: emails[0] || '', emails: emails,
                phone: l.phone || '', city: l.city || '', state: l.state || '',
                website: l.website || '', address: l.address || '',
                socials: l.social_media_links || [], ghl_contact_id: l.ghl_contact_id || null,
                _selected: true
            };
        });
    }

    async _loadGHLTagsWorkflows(connId, locId) {
        let tagsData, workflowsData;
        for (let attempt = 0; attempt < 2; attempt++) {
            [tagsData, workflowsData] = await Promise.all([
                this.api('ghlProxy', { method: 'GET', endpoint: `/locations/${locId}/tags`, connection_id: connId }, 'POST'),
                this.api('ghlProxy', { method: 'GET', endpoint: `/workflows/?locationId=${locId}`, connection_id: connId }, 'POST')
            ]);
            if (tagsData?.success && tagsData?.data?.tags) break;
            if (attempt === 0) await new Promise(r => setTimeout(r, 1000));
        }
        const rawTags = tagsData?.data?.tags || tagsData?.data?.Tags || tagsData?.tags || [];
        this._ghlTags = rawTags.map(t => String(typeof t === 'object' ? (t.name ?? t.Name ?? '') : t)).filter(t => t.trim() !== '');
        this._ghlWorkflows = workflowsData?.data?.workflows || workflowsData?.data?.Workflows || [];
        if (!this._ghlTags.length && tagsData && !tagsData.success) this.toast('Could not load GHL tags — try refreshing or check your connection');
        const wfSelect = document.getElementById('ghlWorkflowSelect');
        wfSelect.innerHTML = '<option value="">No workflow</option>' + this._ghlWorkflows.map(w => `<option value="${w.id}">${this.esc(w.name)}</option>`).join('');
    }

    async ghlGoToPage(page, forceRefresh) {
        if (page < 1) return;
        if (!forceRefresh && (page > this._ghlTotalPages || page === this._ghlPage)) return;
        this._ghlPage = page;
        document.getElementById('ghlSpreadsheetBody').innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i></td></tr>';

        const params = { list_id: this.currentList.id, page, per_page: this._ghlPerPage };
        const search = (document.getElementById('ghlSearchInput')?.value || '');
        const filter = document.getElementById('ghlFilterSelect')?.value || 'all';
        const importFilter = document.getElementById('ghlImportFilter')?.value || 'any';
        if (search) params.search = search;
        if (filter === 'has_email') params.has = 'email';
        else if (filter === 'has_phone') params.has = 'phone';
        else if (filter === 'has_both') params.has = 'email';

        const data = await this.api('exportLeads', params);
        if (!data.success) return;

        let leads = this._mapLeadsToGHL(data.leads);

        if (filter === 'has_both') leads = leads.filter(l => l.email && l.phone);
        if (importFilter === 'not_imported') leads = leads.filter(l => !l.ghl_contact_id);
        else if (importFilter === 'previously_imported') leads = leads.filter(l => !!l.ghl_contact_id);

        this._ghlTotalLeads = data.total;
        this._ghlTotalPages = data.total_pages;
        this._ghlLeads = leads;
        this._ghlSelected = new Set(leads.map((_, i) => i));
        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    filterGHLLeads() {
        this._ghlPage = 0;
        this.ghlGoToPage(1, true);
    }

    renderGHLSpreadsheet() {
        const body = document.getElementById('ghlSpreadsheetBody');
        const leads = this._ghlLeads;

        const socialIcon = (url) => {
            const u = url.toLowerCase();
            if (u.includes('facebook') || u.includes('fb.')) return 'fab fa-facebook';
            if (u.includes('instagram')) return 'fab fa-instagram';
            if (u.includes('twitter') || u.includes('x.com')) return 'fab fa-twitter';
            if (u.includes('linkedin')) return 'fab fa-linkedin';
            if (u.includes('youtube') || u.includes('youtu.be')) return 'fab fa-youtube';
            if (u.includes('tiktok')) return 'fab fa-tiktok';
            if (u.includes('yelp')) return 'fab fa-yelp';
            return 'fas fa-link';
        };

        body.innerHTML = leads.map((l, idx) => {
            const checked = this._ghlSelected.has(idx) ? 'checked' : '';
            const emailOptions = l.emails.length > 0
                ? l.emails.map(e => `<option value="${this.esc(e)}" ${e === l.email ? 'selected' : ''}>${this.esc(e)}</option>`).join('') + '<option value="__custom">+ Add email...</option>'
                : '<option value="">No email</option><option value="__custom">+ Add email...</option>';
            const socialsHtml = (l.socials || []).slice(0, 4).map(s => `<a href="${this.esc(s)}" target="_blank" title="${this.esc(s)}"><i class="${socialIcon(s)}"></i></a>`).join('');

            const impBadge = l.ghl_contact_id ? '<span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#34C759;margin-left:4px;" title="In your Free CRM"></span>' : '';

            return `<tr style="${!this._ghlSelected.has(idx) ? 'opacity:0.4;' : ''}">
                <td><input type="checkbox" ${checked} onchange="app.ghlToggleOne(${idx}, this.checked)" style="accent-color:var(--accent);"></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.firstName)}" onchange="app._ghlLeads[${idx}].firstName=this.value" placeholder="First"></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.lastName)}" onchange="app._ghlLeads[${idx}].lastName=this.value" placeholder="Last"></td>
                <td><div style="display:flex;align-items:center;"><input class="ghl-cell-input" value="${this.esc(l.companyName)}" onchange="app._ghlLeads[${idx}].companyName=this.value" style="font-weight:600;">${impBadge}</div></td>
                <td><div class="ghl-email-cell"><select class="ghl-email-select" onchange="app.ghlEmailChange(${idx}, this)">${emailOptions}</select></div></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.phone)}" onchange="app._ghlLeads[${idx}].phone=this.value"></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.city)}" onchange="app._ghlLeads[${idx}].city=this.value"></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.state)}" onchange="app._ghlLeads[${idx}].state=this.value" style="width:50px;"></td>
                <td><input class="ghl-cell-input" value="${this.esc(l.website)}" onchange="app._ghlLeads[${idx}].website=this.value" style="font-size:11px;"></td>
                <td><div class="ghl-socials-cell">${socialsHtml || '<span style="color:var(--text-tertiary);font-size:11px;">—</span>'}</div></td>
            </tr>`;
        }).join('');

        const tp = this._ghlTotalPages || 1;
        const cp = this._ghlPage || 1;
        let pagHtml = '';
        if (tp > 1) {
            pagHtml = `<div style="display:flex;align-items:center;justify-content:center;gap:6px;padding:10px 0;font-size:12px;">
                <button class="btn btn-ghost" onclick="app.ghlGoToPage(${cp - 1})" ${cp <= 1 ? 'disabled' : ''} style="padding:4px 10px;font-size:12px;"><i class="fas fa-chevron-left"></i></button>
                <span style="color:var(--text-secondary);">Page ${cp} of ${tp}</span>
                <button class="btn btn-ghost" onclick="app.ghlGoToPage(${cp + 1})" ${cp >= tp ? 'disabled' : ''} style="padding:4px 10px;font-size:12px;"><i class="fas fa-chevron-right"></i></button>
                <span style="margin-left:8px;color:var(--text-tertiary);">${(this._ghlTotalLeads || 0).toLocaleString()} total</span>
            </div>`;
        }
        document.getElementById('ghlLeadCount').innerHTML = `${(this._ghlTotalLeads || leads.length).toLocaleString()} leads` + pagHtml;
    }

    ghlEmailChange(idx, select) {
        if (select.value === '__custom') {
            const newEmail = prompt('Enter email address:');
            if (newEmail && newEmail.includes('@')) {
                this._ghlLeads[idx].emails.push(newEmail);
                this._ghlLeads[idx].email = newEmail;
            }
            this.renderGHLSpreadsheet();
        } else {
            this._ghlLeads[idx].email = select.value;
        }
    }

    ghlToggleOne(idx, checked) {
        if (checked) this._ghlSelected.add(idx);
        else this._ghlSelected.delete(idx);
        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    ghlToggleSelectAll(checked) {
        this._ghlLeads.forEach((_, idx) => {
            if (checked) this._ghlSelected.add(idx);
            else this._ghlSelected.delete(idx);
        });
        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    ghlSelectAll() {
        this._ghlLeads.forEach((_, idx) => {
            this._ghlSelected.add(idx);
        });
        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    ghlDeselectAll() {
        this._ghlSelected.clear();
        this.renderGHLSpreadsheet();
        this.updateGHLSummary();
    }

    updateGHLSummary() {
        const sel = this._ghlTotalLeads || this._ghlSelected.size;
        const tags = this._ghlSelectedTags.length;
        const wf = document.getElementById('ghlWorkflowSelect')?.value;
        const wfName = wf ? document.getElementById('ghlWorkflowSelect').selectedOptions[0]?.textContent : '';

        let parts = [`<strong>${sel.toLocaleString()}</strong> contacts selected`];
        if (tags > 0) parts.push(`${tags} tag${tags > 1 ? 's' : ''}`);
        if (wfName) parts.push(`workflow: ${wfName}`);
        document.getElementById('ghlSummaryText').innerHTML = parts.join(' · ');
    }

    showGHLTagDropdown() {
        const dropdown = document.getElementById('ghlTagDropdown');
        dropdown.classList.remove('hidden');
        this.filterGHLTags();
        setTimeout(() => {
            const handler = (e) => {
                if (!e.target.closest('#ghlTagsArea')) { dropdown.classList.add('hidden'); document.removeEventListener('click', handler); }
            };
            document.addEventListener('click', handler);
        }, 10);
    }

    filterGHLTags() {
        const q = (document.getElementById('ghlTagInput')?.value || '').toLowerCase();
        const dropdown = document.getElementById('ghlTagDropdown');
        const existing = new Set(this._ghlSelectedTags);
        const filtered = this._ghlTags.filter(t => !existing.has(t) && t.toLowerCase().includes(q));

        let html = filtered.slice(0, 30).map(t => `<div class="ghl-tag-option" onclick="app.addGHLTag('${this.esc(t)}')">${this.esc(t)}</div>`).join('');
        if (q && !this._ghlTags.some(t => t.toLowerCase() === q) && !existing.has(q)) {
            html = `<div class="ghl-tag-option" onclick="app.addGHLTag('${this.esc(q)}')"><i class="fas fa-plus" style="color:var(--accent);"></i> Create "${this.esc(q)}"</div>` + html;
        }
        if (!html) html = '<div style="padding:12px;text-align:center;color:var(--text-tertiary);font-size:12px;">No tags found</div>';
        dropdown.innerHTML = html;
    }

    addGHLTag(tag) {
        if (!this._ghlSelectedTags.includes(tag)) this._ghlSelectedTags.push(tag);
        document.getElementById('ghlTagInput').value = '';
        document.getElementById('ghlTagDropdown').classList.add('hidden');
        this.renderGHLTagChips();
        this.updateGHLSummary();
    }

    removeGHLTag(tag) {
        this._ghlSelectedTags = this._ghlSelectedTags.filter(t => t !== tag);
        this.renderGHLTagChips();
        this.updateGHLSummary();
    }

    renderGHLTagChips() {
        document.getElementById('ghlSelectedTags').innerHTML = this._ghlSelectedTags.map(t =>
            `<span class="ghl-tag-chip">${this.esc(t)}<button onclick="app.removeGHLTag('${this.esc(t)}')">&times;</button></span>`
        ).join('');
    }

    closeGHLExport() {
        document.getElementById('ghlExportOverlay').classList.remove('active');
        document.getElementById('ghlSettingsSlide').classList.add('hidden');
        document.getElementById('ghlDripSlide').classList.add('hidden');
        document.getElementById('ghlLogsSlide').classList.add('hidden');
        if (this.currentList) { this.loadLeads(); this.refreshCurrentList(); }
    }

    openGHLSettings() {
        this.renderGHLSettingsConnections();
        document.getElementById('ghlSettingsSlide').classList.remove('hidden');
    }

    renderGHLSettingsConnections() {
        const container = document.getElementById('ghlSettingsConnectionsList');
        if (!container) return;
        if (this._ghlConnections.length === 0) {
            container.innerHTML = '<div style="text-align:center;padding:16px;color:var(--text-tertiary);font-size:12px;">No connections</div>';
            return;
        }
        container.innerHTML = this._ghlConnections.map(c => {
            const isActive = this._ghlActiveConn?.id == c.id;
            return `<div style="display:flex;align-items:center;gap:10px;padding:12px;background:${isActive ? 'rgba(200,87,25,0.06)' : 'var(--bg)'};border:1px solid ${isActive ? 'var(--accent)' : 'var(--card-border)'};border-radius:8px;margin-bottom:6px;">
                <div style="width:32px;height:32px;border-radius:8px;background:linear-gradient(135deg,#FF6B35,#FF3B30);display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="fas fa-bolt" style="color:#fff;font-size:12px;"></i></div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;font-size:13px;">${this.esc(c.name)}${isActive ? ' <span style="font-size:10px;color:var(--accent);">(active)</span>' : ''}</div>
                    <div style="font-size:10px;color:var(--text-tertiary);">Location: ${this.esc(c.location_id)}</div>
                </div>
                <button class="btn-icon" onclick="app.editGHLConnection(${c.id})" title="Edit" style="font-size:11px;"><i class="fas fa-pen"></i></button>
                <button class="btn-icon" onclick="app.deleteGHLConnection(${c.id})" title="Delete" style="font-size:11px;color:var(--red);"><i class="fas fa-trash"></i></button>
            </div>`;
        }).join('');
    }

    closeGHLSettings() {
        document.getElementById('ghlSettingsSlide').classList.add('hidden');
    }

    toggleDripFields() {
        document.getElementById('ghlDripFields').classList.toggle('hidden', !document.getElementById('ghlDripEnabled').checked);
    }

    openGHLDripConfig() {
        document.getElementById('ghlDripSlide').classList.remove('hidden');
    }

    getDripConfig() {
        const enabled = document.getElementById('ghlDripEnabled')?.checked;
        if (!enabled) return null;
        const hour = document.getElementById('ghlDripHour')?.value;
        return {
            enabled: true,
            batch_size: parseInt(document.getElementById('ghlDripBatch')?.value || '50'),
            interval_minutes: parseInt(document.getElementById('ghlDripInterval')?.value || '60'),
            timezone: document.getElementById('ghlDripTimezone')?.value || 'America/New_York',
            send_hour: hour !== '' ? parseInt(hour) : null,
            send_minute: 0
        };
    }

    async startGHLImport() {
        const totalToImport = this._ghlTotalLeads || this._ghlLeads.length;
        if (totalToImport === 0) { this.toast('No leads to import'); return; }

        const tags = this._ghlSelectedTags;
        const wfId = document.getElementById('ghlWorkflowSelect')?.value || '';
        const wfName = wfId ? document.getElementById('ghlWorkflowSelect').selectedOptions[0]?.textContent : '';
        const drip = this.getDripConfig();

        const filters = {
            has: document.getElementById('ghlFilterSelect')?.value || '',
            search: document.getElementById('ghlSearchInput')?.value || '',
            import_filter: document.getElementById('ghlImportFilter')?.value || ''
        };

        const summary = [`Import ${totalToImport.toLocaleString()} contacts to your Free CRM`];
        if (tags.length) summary.push(`Tags: ${tags.join(', ')}`);
        if (wfName) summary.push(`Workflow: ${wfName}`);
        if (drip) summary.push(`Drip: ${drip.batch_size} per batch, every ${drip.interval_minutes} min`);
        if (!confirm(summary.join('\n') + '\n\nProceed?')) return;

        this.showGHLScreen('ghlImportingScreen');
        document.getElementById('ghlImportBar').style.width = '0%';
        document.getElementById('ghlImportPct').textContent = '0%';
        document.getElementById('ghlImportProgressLabel').textContent = `0 of ${totalToImport}`;
        document.getElementById('ghlStatNew').textContent = '0';
        document.getElementById('ghlStatUpdated').textContent = '0';
        document.getElementById('ghlStatFailed').textContent = '0';

        const createRes = await this.api('ghlCreateImportFromList', {
            list_id: this.currentList?.id,
            tags,
            workflow_id: wfId,
            workflow_name: wfName,
            connection_id: this._ghlActiveConn?.id || 0,
            drip,
            filters
        }, 'POST');

        if (!createRes.success) { this.toast('Failed to create import: ' + (createRes.error || '')); this.showGHLScreen('ghlEditorScreen'); return; }

        this._currentImportId = createRes.import_id;
        const importTotal = createRes.total || totalToImport;

        if (createRes.mode === 'drip' && drip?.send_hour !== null) {
            this.showGHLDoneScreen(0, 0, 0, [], tags, wfName, true, createRes);
            return;
        }

        await this.processGHLImport(createRes.import_id, importTotal, tags, wfName);
    }

    async processGHLImport(importId, total, tags, wfName) {
        let done = false;
        while (!done) {
            const statusCheck = await this.api('ghlGetImportStatus', { import_id: importId });
            if (statusCheck.success && statusCheck.log?.status === 'paused') {
                await new Promise(r => setTimeout(r, 2000));
                continue;
            }
            if (statusCheck.success && statusCheck.log?.status === 'cancelled') { done = true; break; }

            const res = await this.api('ghlProcessBatch', { import_id: importId }, 'POST');
            if (!res.success) { this.toast('Batch error: ' + (res.error || '')); break; }

            if (res.totals) {
                const t = res.totals;
                const processed = parseInt(t.processed || 0);
                const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                document.getElementById('ghlImportBar').style.width = pct + '%';
                document.getElementById('ghlImportPct').textContent = pct + '%';
                document.getElementById('ghlImportProgressLabel').textContent = `${processed} of ${total}`;
                document.getElementById('ghlStatNew').textContent = t.imported || 0;
                document.getElementById('ghlStatUpdated').textContent = t.updated || 0;
                document.getElementById('ghlStatFailed').textContent = t.failed || 0;
            }

            if (res.waiting) {
                const t = res.totals || {};
                this.showGHLDoneScreen(
                    parseInt(t.imported || 0),
                    parseInt(t.updated || 0),
                    parseInt(t.failed || 0),
                    [], tags, wfName, false, null,
                    { remaining: res.remaining, waitSeconds: res.wait_seconds, nextBatchAt: res.drip_next_batch_at, importId }
                );
                return;
            }

            done = res.done;
        }

        const finalStatus = await this.api('ghlGetImportStatus', { import_id: importId });
        const fl = finalStatus.log || {};
        this.showGHLDoneScreen(
            parseInt(fl.imported || 0),
            parseInt(fl.updated || 0),
            parseInt(fl.failed || 0),
            fl.errors || [],
            tags, wfName, false, null
        );
    }

    showGHLDoneScreen(imported, updated, failed, errors, tags, wfName, isDripScheduled, createRes, dripProgress) {
        this.showGHLScreen('ghlDoneScreen');

        if (isDripScheduled) {
            document.getElementById('ghlDoneSubtitle').textContent = 'Drip import scheduled';
            let summaryHtml = `<div style="text-align:center;margin-bottom:16px;">
                <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#c85719,#1460a6);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                    <i class="fas fa-clock" style="font-size:24px;color:#fff;"></i>
                </div>
                <div style="font-size:15px;font-weight:600;">Contacts queued for drip delivery</div>
                <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">First batch will send at the scheduled time.</div>
            </div>`;
            if (tags.length) summaryHtml += `<div style="margin-bottom:6px;"><strong>Tags:</strong> ${tags.map(t => `<span class="ghl-tag-chip" style="font-size:10px;">${this.esc(t)}</span>`).join(' ')}</div>`;
            if (wfName) summaryHtml += `<div><strong>Workflow:</strong> ${this.esc(wfName)}</div>`;
            document.getElementById('ghlDoneSummary').innerHTML = summaryHtml;
            document.getElementById('ghlDoneErrors').classList.add('hidden');
            return;
        }

        if (dripProgress) {
            const total = imported + updated + failed;
            document.getElementById('ghlDoneSubtitle').textContent = 'Batch complete — drip in progress';
            const waitMin = Math.ceil((dripProgress.waitSeconds || 0) / 60);
            let summaryHtml = `<div style="display:flex;gap:20px;margin-bottom:12px;">
                <div><span style="font-size:24px;font-weight:700;color:var(--green);">${imported}</span><div style="font-size:11px;color:var(--text-tertiary);">New contacts</div></div>
                <div><span style="font-size:24px;font-weight:700;color:var(--blue);">${updated}</span><div style="font-size:11px;color:var(--text-tertiary);">Updated</div></div>
                <div><span style="font-size:24px;font-weight:700;color:var(--red);">${failed}</span><div style="font-size:11px;color:var(--text-tertiary);">Failed</div></div>
            </div>`;
            summaryHtml += `<div style="padding:12px 16px;background:#EDE9FE;border-radius:10px;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <i class="fas fa-clock" style="color:#5B21B6;"></i>
                    <strong style="color:#5B21B6;">Next batch in ~${waitMin} min</strong>
                </div>
                <div style="font-size:12px;color:#6B21A8;">${dripProgress.remaining} contacts remaining — check Import Logs for live status</div>
            </div>`;
            if (tags.length) summaryHtml += `<div style="margin-bottom:6px;"><strong>Tags applied:</strong> ${tags.map(t => `<span class="ghl-tag-chip" style="font-size:10px;">${this.esc(t)}</span>`).join(' ')}</div>`;
            if (wfName) summaryHtml += `<div><strong>Workflow:</strong> ${this.esc(wfName)}</div>`;
            document.getElementById('ghlDoneSummary').innerHTML = summaryHtml;
            document.getElementById('ghlDoneErrors').classList.add('hidden');
            this.fireConfetti();
            return;
        }

        const total = imported + updated + failed;
        document.getElementById('ghlDoneSubtitle').textContent = `${total.toLocaleString()} contacts processed`;

        let summaryHtml = `<div style="display:flex;gap:20px;margin-bottom:12px;">
            <div><span style="font-size:24px;font-weight:700;color:var(--green);">${imported}</span><div style="font-size:11px;color:var(--text-tertiary);">New contacts</div></div>
            <div><span style="font-size:24px;font-weight:700;color:var(--blue);">${updated}</span><div style="font-size:11px;color:var(--text-tertiary);">Updated</div></div>
            <div><span style="font-size:24px;font-weight:700;color:var(--red);">${failed}</span><div style="font-size:11px;color:var(--text-tertiary);">Failed</div></div>
        </div>`;
        if (tags.length) summaryHtml += `<div style="margin-bottom:6px;"><strong>Tags applied:</strong> ${tags.map(t => `<span class="ghl-tag-chip" style="font-size:10px;">${this.esc(t)}</span>`).join(' ')}</div>`;
        if (wfName) summaryHtml += `<div><strong>Workflow:</strong> ${this.esc(wfName)}</div>`;
        document.getElementById('ghlDoneSummary').innerHTML = summaryHtml;

        if (errors.length > 0) {
            document.getElementById('ghlDoneErrors').classList.remove('hidden');
            document.getElementById('ghlDoneErrorList').innerHTML = errors.slice(0, 20).map(e => `<div style="margin-bottom:4px;">\u2022 ${this.esc(e)}</div>`).join('');
        } else {
            document.getElementById('ghlDoneErrors').classList.add('hidden');
        }

        this.fireConfetti();
    }

    async showGHLImportLogs() {
        document.getElementById('ghlLogsSlide').classList.remove('hidden');
        const content = document.getElementById('ghlLogsContent');
        content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

        const res = await this.api('ghlGetImportLogs', { list_id: this.currentList?.id || 0 });
        if (!res.success || !res.logs?.length) {
            content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-tertiary);"><i class="fas fa-inbox" style="font-size:24px;margin-bottom:8px;display:block;"></i>No imports yet</div>';
            return;
        }

        content.innerHTML = res.logs.map(log => {
            const pct = log.total_contacts > 0 ? Math.round((log.processed / log.total_contacts) * 100) : 0;
            const date = new Date(log.created_at).toLocaleString();
            const tags = (log.tags || []).slice(0, 3);
            const isActive = ['running','pending','paused'].includes(log.status);

            return `<div class="ghl-log-card" onclick="app.openImportLogDetail(${log.id})">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-weight:600;">${this.esc(log.list_name || 'List #' + log.list_id)}</span>
                    <span class="ghl-log-status ${log.status}">${log.status}</span>
                </div>
                <div style="display:flex;gap:16px;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">
                    <span><i class="fas fa-users"></i> ${log.total_contacts}</span>
                    <span style="color:var(--green);"><i class="fas fa-plus"></i> ${log.imported}</span>
                    <span style="color:var(--blue);"><i class="fas fa-sync"></i> ${log.updated}</span>
                    <span style="color:var(--red);"><i class="fas fa-times"></i> ${log.failed}</span>
                </div>
                <div style="font-size:11px;color:var(--text-tertiary);">${date}${log.connection_name ? ' · <i class="fas fa-bolt"></i> ' + this.esc(log.connection_name) : ''}${log.drip_enabled ? ' · <i class="fas fa-clock"></i> Drip' : ''}</div>
                ${log.drip_enabled && log.drip_next_batch_at && log.status === 'pending' ? (() => { const ms = new Date(log.drip_next_batch_at + 'Z').getTime() - Date.now(); const m = Math.max(0, Math.ceil(ms/60000)); return '<div style="font-size:11px;color:#5B21B6;margin-top:4px;padding:4px 8px;background:#EDE9FE;border-radius:6px;display:inline-block;"><i class="fas fa-clock"></i> Next batch in ~' + m + ' min</div>'; })() : ''}
                ${tags.length ? '<div style="margin-top:6px;">' + tags.map(t => `<span class="ghl-tag-chip" style="font-size:9px;">${this.esc(t)}</span>`).join(' ') + '</div>' : ''}
                ${isActive || pct < 100 ? `<div class="ghl-log-progress"><div class="ghl-log-progress-bar" style="width:${pct}%;"></div></div>` : ''}
            </div>`;
        }).join('');
    }

    async openImportLogDetail(importId) {
        const res = await this.api('ghlGetImportStatus', { import_id: importId });
        if (!res.success || !res.log) { this.toast('Could not load import details'); return; }
        const log = res.log;
        const pct = log.total_contacts > 0 ? Math.round((log.processed / log.total_contacts) * 100) : 0;
        const isActive = ['running','pending','paused'].includes(log.status);
        const date = new Date(log.created_at).toLocaleString();
        const tags = log.tags || [];
        const errors = log.errors || [];

        let actions = '';
        if (log.status === 'running' || log.status === 'pending') {
            actions += `<button class="btn btn-sm" onclick="app.pauseGHLImport(${importId})" style="font-size:12px;"><i class="fas fa-pause"></i> Pause</button>`;
            actions += `<button class="btn btn-sm" onclick="app.cancelGHLImport(${importId})" style="font-size:12px;color:var(--red);"><i class="fas fa-stop"></i> Cancel</button>`;
        }
        if (log.status === 'paused') {
            actions += `<button class="btn btn-sm" onclick="app.resumeGHLImport(${importId})" style="font-size:12px;"><i class="fas fa-play"></i> Resume</button>`;
            actions += `<button class="btn btn-sm" onclick="app.cancelGHLImport(${importId})" style="font-size:12px;color:var(--red);"><i class="fas fa-stop"></i> Cancel</button>`;
        }

        document.getElementById('ghlLogsContent').innerHTML = `
            <button class="btn btn-sm" onclick="app.showGHLImportLogs()" style="margin-bottom:12px;font-size:12px;"><i class="fas fa-arrow-left"></i> Back</button>
            <div style="background:var(--bg);border:1px solid var(--card-border);border-radius:10px;padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                    <span style="font-weight:700;font-size:15px;">Import #${log.id}</span>
                    <span class="ghl-log-status ${log.status}">${log.status}</span>
                </div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;">${date}</div>
                <div style="display:flex;gap:16px;margin-bottom:12px;">
                    <div style="text-align:center;"><div style="font-size:20px;font-weight:700;">${log.total_contacts}</div><div style="font-size:10px;color:var(--text-tertiary);">Total</div></div>
                    <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--green);">${log.imported}</div><div style="font-size:10px;color:var(--text-tertiary);">New</div></div>
                    <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--blue);">${log.updated}</div><div style="font-size:10px;color:var(--text-tertiary);">Updated</div></div>
                    <div style="text-align:center;"><div style="font-size:20px;font-weight:700;color:var(--red);">${log.failed}</div><div style="font-size:10px;color:var(--text-tertiary);">Failed</div></div>
                </div>
                <div class="ghl-log-progress" style="margin-bottom:12px;"><div class="ghl-log-progress-bar" style="width:${pct}%;"></div></div>
                <div style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;">${log.processed} / ${log.total_contacts} processed (${pct}%)</div>
                ${log.drip_enabled ? `<div style="font-size:12px;margin-bottom:12px;padding:8px 12px;background:#EDE9FE;border-radius:8px;"><i class="fas fa-clock" style="color:#5B21B6;"></i> <strong>Drip:</strong> ${log.drip_batch_size} per batch, every ${log.drip_interval_minutes} min (${log.drip_timezone})</div>` : ''}
                ${tags.length ? '<div style="margin-bottom:12px;">' + tags.map(t => `<span class="ghl-tag-chip" style="font-size:10px;">${this.esc(t)}</span>`).join(' ') + '</div>' : ''}
                ${log.connection_name ? `<div style="font-size:12px;margin-bottom:12px;"><i class="fas fa-bolt" style="color:var(--accent);"></i> <strong>Connection:</strong> ${this.esc(log.connection_name)}</div>` : ''}
                ${log.workflow_name ? `<div style="font-size:12px;margin-bottom:12px;"><strong>Workflow:</strong> ${this.esc(log.workflow_name)}</div>` : ''}
                ${actions ? `<div style="display:flex;gap:8px;margin-bottom:12px;">${actions}</div>` : ''}
                ${errors.length ? `<div style="background:#FFF5F5;border:1px solid #FED7D7;border-radius:8px;padding:12px;max-height:150px;overflow-y:auto;">
                    <div style="font-size:12px;font-weight:600;color:var(--red);margin-bottom:6px;"><i class="fas fa-exclamation-triangle"></i> Errors (${errors.length})</div>
                    ${errors.slice(0, 20).map(e => `<div style="font-size:11px;color:#666;margin-bottom:3px;">• ${this.esc(String(e))}</div>`).join('')}
                </div>` : ''}
            </div>`;
    }

    async pauseGHLImport(importId) {
        await this.api('ghlPauseImport', { import_id: importId }, 'POST');
        this.toast('Import paused');
        this.openImportLogDetail(importId);
    }

    async resumeGHLImport(importId) {
        await this.api('ghlResumeImport', { import_id: importId }, 'POST');
        this.toast('Import resumed');
        this.openImportLogDetail(importId);
    }

    async cancelGHLImport(importId) {
        if (!confirm('Cancel this import? Remaining contacts will not be sent.')) return;
        await this.api('ghlCancelImport', { import_id: importId }, 'POST');
        this.toast('Import cancelled');
        this.openImportLogDetail(importId);
    }

    async pauseCurrentImport() {
        if (!this._currentImportId) return;
        if (this._dripTimer) { clearTimeout(this._dripTimer); this._dripTimer = null; }
        await this.api('ghlPauseImport', { import_id: this._currentImportId }, 'POST');
        this.toast('Import paused — you can resume from Import Logs');
    }

    async cancelCurrentImport() {
        if (!this._currentImportId) return;
        if (!confirm('Cancel this import? Remaining contacts will not be sent.')) return;
        if (this._dripTimer) { clearTimeout(this._dripTimer); this._dripTimer = null; }
        await this.api('ghlCancelImport', { import_id: this._currentImportId }, 'POST');
        this.toast('Import cancelled');
        this.closeGHLExport();
    }

    async openFolderImportLogs() {
        const modal = document.getElementById('importLogsModal');
        modal.classList.add('active');
        const content = document.getElementById('importLogsModalContent');
        content.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-tertiary);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

        const res = await this.api('ghlGetImportLogs', { list_id: this.currentList?.id || 0 });
        if (!res.success || !res.logs?.length) {
            content.innerHTML = '<div style="text-align:center;padding:40px;color:var(--text-tertiary);"><i class="fas fa-inbox" style="font-size:28px;margin-bottom:10px;display:block;"></i><div style="font-size:14px;">No imports yet for this folder</div><div style="font-size:12px;margin-top:4px;">Export leads to your Free CRM to see import history here.</div></div>';
            return;
        }

        content.innerHTML = res.logs.map(log => {
            const pct = log.total_contacts > 0 ? Math.round((log.processed / log.total_contacts) * 100) : 0;
            const date = new Date(log.created_at).toLocaleString();
            const tags = (log.tags || []).slice(0, 3);
            const isActive = ['running','pending','paused'].includes(log.status);
            const errors = log.errors || [];

            let actions = '';
            if (log.status === 'running' || log.status === 'pending') {
                actions = `<div style="display:flex;gap:6px;margin-top:8px;">
                    <button class="btn btn-sm" onclick="event.stopPropagation();app.pauseImportFromFolder(${log.id})" style="font-size:11px;"><i class="fas fa-pause"></i> Pause</button>
                    <button class="btn btn-sm" onclick="event.stopPropagation();app.cancelImportFromFolder(${log.id})" style="font-size:11px;color:var(--red);"><i class="fas fa-stop"></i> Cancel</button>
                </div>`;
            } else if (log.status === 'paused') {
                actions = `<div style="display:flex;gap:6px;margin-top:8px;">
                    <button class="btn btn-sm" onclick="event.stopPropagation();app.resumeImportFromFolder(${log.id})" style="font-size:11px;"><i class="fas fa-play"></i> Resume</button>
                    <button class="btn btn-sm" onclick="event.stopPropagation();app.cancelImportFromFolder(${log.id})" style="font-size:11px;color:var(--red);"><i class="fas fa-stop"></i> Cancel</button>
                </div>`;
            }

            return `<div class="ghl-log-card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <span style="font-weight:600;">Import #${log.id}</span>
                    <span class="ghl-log-status ${log.status}">${log.status}</span>
                </div>
                <div style="display:flex;gap:16px;font-size:12px;color:var(--text-secondary);margin-bottom:4px;">
                    <span><i class="fas fa-users"></i> ${log.total_contacts} contacts</span>
                    <span style="color:var(--green);"><i class="fas fa-plus"></i> ${log.imported} new</span>
                    <span style="color:var(--blue);"><i class="fas fa-sync"></i> ${log.updated} updated</span>
                    ${log.failed > 0 ? `<span style="color:var(--red);"><i class="fas fa-times"></i> ${log.failed} failed</span>` : ''}
                </div>
                <div style="font-size:11px;color:var(--text-tertiary);">${date}${log.connection_name ? ' · <i class="fas fa-bolt"></i> ' + this.esc(log.connection_name) : ''}${log.drip_enabled ? ' · <i class="fas fa-clock"></i> Drip (' + log.drip_batch_size + '/batch, ' + log.drip_interval_minutes + 'min)' : ''}${log.workflow_name ? ' · <i class="fas fa-project-diagram"></i> ' + this.esc(log.workflow_name) : ''}</div>
                ${log.drip_enabled && log.drip_next_batch_at && log.status === 'pending' ? (() => { const ms = new Date(log.drip_next_batch_at + 'Z').getTime() - Date.now(); const m = Math.max(0, Math.ceil(ms/60000)); return '<div style="font-size:11px;color:#5B21B6;margin-top:4px;padding:4px 8px;background:#EDE9FE;border-radius:6px;display:inline-block;"><i class="fas fa-clock"></i> Next batch in ~' + m + ' min</div>'; })() : ''}
                ${tags.length ? '<div style="margin-top:6px;">' + tags.map(t => `<span class="ghl-tag-chip" style="font-size:9px;">${this.esc(t)}</span>`).join(' ') + '</div>' : ''}
                ${isActive || pct < 100 ? `<div class="ghl-log-progress"><div class="ghl-log-progress-bar" style="width:${pct}%;"></div></div><div style="font-size:11px;color:var(--text-tertiary);margin-top:3px;">${log.processed} / ${log.total_contacts} (${pct}%)</div>` : ''}
                ${errors.length ? `<details style="margin-top:8px;"><summary style="font-size:11px;color:var(--red);cursor:pointer;font-weight:600;">${errors.length} error(s)</summary><div style="font-size:11px;color:#666;margin-top:4px;max-height:80px;overflow-y:auto;">${errors.slice(0, 10).map(e => `<div>• ${this.esc(String(e))}</div>`).join('')}</div></details>` : ''}
                ${actions}
            </div>`;
        }).join('');
    }

    async pauseImportFromFolder(importId) {
        await this.api('ghlPauseImport', { import_id: importId }, 'POST');
        this.toast('Import paused');
        this.openFolderImportLogs();
    }

    async resumeImportFromFolder(importId) {
        await this.api('ghlResumeImport', { import_id: importId }, 'POST');
        this.toast('Import resumed');
        this.openFolderImportLogs();
    }

    async cancelImportFromFolder(importId) {
        if (!confirm('Cancel this import?')) return;
        await this.api('ghlCancelImport', { import_id: importId }, 'POST');
        this.toast('Import cancelled');
        this.openFolderImportLogs();
    }

    fireConfetti() {
        const canvas = document.getElementById('confettiCanvas');
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        canvas.width = canvas.offsetWidth;
        canvas.height = canvas.offsetHeight;
        const particles = [];
        const colors = ['#FF3B30','#ca942a','#FFCC00','#34C759','#c85719','#337f83','#FF2D55'];
        for (let i = 0; i < 150; i++) {
            particles.push({
                x: canvas.width / 2 + (Math.random() - 0.5) * 200,
                y: canvas.height / 2,
                vx: (Math.random() - 0.5) * 12,
                vy: -Math.random() * 12 - 4,
                w: Math.random() * 8 + 4,
                h: Math.random() * 6 + 3,
                color: colors[Math.floor(Math.random() * colors.length)],
                rot: Math.random() * Math.PI * 2,
                rv: (Math.random() - 0.5) * 0.3,
                gravity: 0.15 + Math.random() * 0.05,
                alpha: 1
            });
        }
        let frame = 0;
        const animate = () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            let alive = false;
            particles.forEach(p => {
                p.x += p.vx;
                p.vy += p.gravity;
                p.y += p.vy;
                p.rot += p.rv;
                if (frame > 60) p.alpha -= 0.01;
                if (p.alpha <= 0) return;
                alive = true;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.globalAlpha = Math.max(0, p.alpha);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            });
            frame++;
            if (alive && frame < 200) requestAnimationFrame(animate);
            else ctx.clearRect(0, 0, canvas.width, canvas.height);
        };
        animate();
    }

    openWebPreview(url, leadId) {
        if (leadId) this.api('updateLead', { id: leadId, visited_website: 1 }, 'POST').then(() => this.refreshCurrentList());

        const noIframeDomains = ['facebook.com','fb.com','fb.me','instagram.com','instagr.am','twitter.com','x.com','linkedin.com','youtube.com','youtu.be','tiktok.com'];
        const isSocial = (u) => noIframeDomains.some(d => u.toLowerCase().includes(d));

        const toSafe = (u) => {
            if (u.startsWith('http://')) return u.replace('http://', 'https://');
            if (!u.startsWith('https://')) return 'https://' + u;
            return u;
        };
        const toOriginal = (u) => u.startsWith('http') ? u : 'https://' + u;

        const lead = leadId ? (this.currentLeads.find(l => l.id == leadId) || this.currentLeadDetail) : null;

        const socialLinks = [];
        let websiteLink = null;
        if (lead) {
            (lead.social_media_links || []).forEach(surl => {
                const p = SOCIAL_PLATFORMS.find(pl => pl.patterns.some(pat => surl.toLowerCase().includes(pat)));
                socialLinks.push({ url: surl, icon: p ? p.icon : 'fas fa-link', name: p ? p.name : 'Link' });
            });
            if (lead.website) {
                websiteLink = { url: lead.website, icon: 'fas fa-globe', name: 'Website' };
            }
        }

        const visitedSocials = lead ? (lead.visited_socials || []) : [];
        const socialBtns = socialLinks.map(l => {
            const isVisited = visitedSocials.includes(l.url);
            return `<button class="preview-link-btn ${isVisited ? 'visited' : ''}" onclick="app.trackSocialVisit(${leadId}, '${this.esc(l.url)}')" title="${l.name}" style="${isVisited ? 'color:var(--green);' : ''}"><i class="${l.icon}"></i></button>`;
        }).join('');
        const websiteBtn = websiteLink
            ? `<button class="preview-link-btn active" onclick="app.switchPreviewUrl(this, '${this.esc(websiteLink.url)}')" title="Website"><i class="fas fa-globe"></i></button>`
            : '';
        const leadEmails = lead ? (lead.emails || []) : [];
        const emailBtn = leadEmails.length > 0
            ? `<button class="preview-link-btn" onclick="app.sendTemplateEmail('${this.esc(leadEmails[0])}', ${leadId})" title="Email ${this.esc(leadEmails[0])}" style="color:#c85719;"><i class="fas fa-envelope"></i></button>`
            : '';
        const hasBar = socialLinks.length > 0 || websiteLink || emailBtn;

        if (isSocial(url) && !lead) {
            window.open(toOriginal(url), '_blank');
            return;
        }

        const existing = document.querySelector('.web-preview-overlay');
        if (existing) existing.remove();

        const iframeUrl = isSocial(url) && websiteLink ? toSafe(websiteLink.url) : toSafe(url);
        const displayUrl = isSocial(url) && websiteLink ? websiteLink.url : url;

        if (isSocial(url) && !websiteLink) {
            window.open(toOriginal(url), '_blank');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'web-preview-overlay';
        overlay.onclick = (e) => { if (e.target === overlay) overlay.remove(); };
        overlay.innerHTML = `
            <div class="web-preview-window">
                <div class="web-preview-titlebar">
                    <div class="titlebar-dots">
                        <button class="titlebar-dot dot-close" onclick="this.closest('.web-preview-overlay').remove()"></button>
                        <button class="titlebar-dot dot-minimize" onclick="this.closest('.web-preview-overlay').remove()"></button>
                        <button class="titlebar-dot dot-maximize" onclick="window.open('${toOriginal(displayUrl)}','_blank')"></button>
                    </div>
                    <div class="titlebar-url" id="previewUrlBar">${displayUrl}</div>
                    <div style="display:flex;align-items:center;gap:4px;">
                        ${hasBar ? `<div class="preview-links-bar">${emailBtn}${emailBtn && (socialBtns || websiteBtn) ? '<div style="width:1px;height:20px;background:#d5d5d5;margin:0 2px;"></div>' : ''}${socialBtns}${socialBtns && websiteBtn ? '<div style="width:1px;height:20px;background:#d5d5d5;margin:0 2px;"></div>' : ''}${websiteBtn}</div>` : ''}
                        <div class="titlebar-actions">
                            <button class="titlebar-btn" id="previewNewTabBtn" onclick="window.open('${toOriginal(displayUrl)}','_blank')" title="Open in new tab"><i class="fas fa-external-link-alt"></i></button>
                            <button class="titlebar-btn" onclick="this.closest('.web-preview-overlay').remove()" title="Close"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <iframe id="previewIframe" src="${iframeUrl}" sandbox="allow-same-origin allow-scripts allow-popups allow-forms"></iframe>
                <div class="iframe-fallback hidden" id="previewFallback" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;color:var(--text-secondary);">
                    <i class="fas fa-globe" style="font-size:40px;color:var(--accent);"></i>
                    <p>This site can't be embedded</p>
                    <a href="${toOriginal(displayUrl)}" target="_blank" class="btn btn-primary" id="previewFallbackLink">Open in New Tab <i class="fas fa-external-link-alt"></i></a>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
    }

    switchPreviewUrl(btn, url) {
        const safeUrl = url.startsWith('http://') ? url.replace('http://', 'https://') : (url.startsWith('https://') ? url : 'https://' + url);
        const originalUrl = url.startsWith('http') ? url : 'https://' + url;

        document.querySelectorAll('.preview-link-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const iframe = document.getElementById('previewIframe');
        const fallback = document.getElementById('previewFallback');
        if (iframe) { iframe.src = safeUrl; iframe.style.display = ''; }
        if (fallback) fallback.classList.add('hidden');
        document.getElementById('previewUrlBar').textContent = url;
        document.getElementById('previewNewTabBtn').onclick = () => window.open(originalUrl, '_blank');
        const fallbackLink = document.getElementById('previewFallbackLink');
        if (fallbackLink) fallbackLink.href = originalUrl;
    }

    openLightbox(index) {
        if (!this.galleryPhotos || this.galleryPhotos.length === 0) return;
        this.lbIndex = index;
        const existing = document.querySelector('.gallery-lightbox');
        if (existing) existing.remove();

        const lb = document.createElement('div');
        lb.className = 'gallery-lightbox';
        lb.id = 'galleryLightbox';
        lb.onclick = (e) => { if (e.target === lb) lb.remove(); };

        const total = this.galleryPhotos.length;
        const thumbsHtml = total > 1 ? `<div class="lb-thumbstrip">${this.galleryPhotos.map((p, i) =>
            `<img class="lb-thumb ${i === index ? 'active' : ''}" src="${this.esc(p.thumb)}" onclick="event.stopPropagation();app.lbGoTo(${i})" onerror="this.style.display='none'">`
        ).join('')}</div>` : '';

        lb.innerHTML = `
            <button class="lb-close" onclick="event.stopPropagation();this.closest('.gallery-lightbox').remove()"><i class="fas fa-times"></i></button>
            ${total > 1 ? `<button class="lb-nav lb-prev" onclick="event.stopPropagation();app.lbPrev()"><i class="fas fa-chevron-left"></i></button>` : ''}
            <img id="lbMainImg" src="${this.esc(this.galleryPhotos[index].hd)}" alt="Photo">
            ${total > 1 ? `<button class="lb-nav lb-next" onclick="event.stopPropagation();app.lbNext()"><i class="fas fa-chevron-right"></i></button>` : ''}
            ${thumbsHtml}
            ${total > 1 ? `<div class="lb-counter"><span id="lbCounter">${index + 1}</span> / ${total}</div>` : ''}
        `;
        document.body.appendChild(lb);

        document.addEventListener('keydown', this._lbKeyHandler = (e) => {
            if (e.key === 'Escape') { lb.remove(); document.removeEventListener('keydown', this._lbKeyHandler); }
            if (e.key === 'ArrowLeft') this.lbPrev();
            if (e.key === 'ArrowRight') this.lbNext();
        });
    }

    lbGoTo(index) {
        if (!this.galleryPhotos) return;
        this.lbIndex = index;
        const img = document.getElementById('lbMainImg');
        const counter = document.getElementById('lbCounter');
        if (img) img.src = this.galleryPhotos[index].hd;
        if (counter) counter.textContent = index + 1;
        document.querySelectorAll('.lb-thumb').forEach((t, i) => t.classList.toggle('active', i === index));
    }

    lbPrev() {
        if (!this.galleryPhotos) return;
        this.lbGoTo(this.lbIndex <= 0 ? this.galleryPhotos.length - 1 : this.lbIndex - 1);
    }

    lbNext() {
        if (!this.galleryPhotos) return;
        this.lbGoTo(this.lbIndex >= this.galleryPhotos.length - 1 ? 0 : this.lbIndex + 1);
    }

    // UTILS

    esc(str) {
        if (!str) return '';
        const d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
}

document.addEventListener('DOMContentLoaded', () => { window.app = new LeadListsApp(); });

document.addEventListener('click', (e) => {
    const menu = document.getElementById('exportMenu');
    const btn = document.getElementById('exportMenuBtn');
    if (menu && !menu.contains(e.target) && !btn.contains(e.target)) {
        menu.classList.add('hidden');
    }
});
</script>

<!-- ================= Guided first-run walkthrough: how to pull leads ================= -->
<style>
  .lt-mask{position:fixed;inset:0;z-index:99998;pointer-events:none;display:none}
  .lt-tip{position:fixed;z-index:100000;max-width:330px;background:#fff;color:#141517;border-radius:14px;box-shadow:0 18px 55px rgba(0,0,0,.32);padding:16px 18px;font-family:inherit;display:none}
  .lt-tip .lt-badge{font-size:11px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#c85719}
  .lt-tip h4{font-size:16px;font-weight:800;margin:6px 0 6px;line-height:1.2}
  .lt-tip p{font-size:13.5px;line-height:1.55;color:#5b6066;margin:0 0 14px}
  .lt-tip p b{color:#141517;font-weight:800}
  .lt-tip .lt-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
  .lt-tip .lt-skip{background:none;border:none;color:#8b9097;font-size:12.5px;font-weight:600;cursor:pointer;padding:6px 2px;font-family:inherit}
  .lt-tip .lt-actions{display:flex;gap:8px}
  .lt-tip .lt-btn{border:none;border-radius:9px;padding:9px 16px;font-size:13.5px;font-weight:800;cursor:pointer;font-family:inherit}
  .lt-tip .lt-next{background:#c85719;color:#fff}
  .lt-tip .lt-back{background:#f0f1f3;color:#141517}
  .lt-help{position:fixed;right:16px;bottom:16px;z-index:99990;background:#141517;color:#fff;border:none;border-radius:999px;padding:11px 16px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;box-shadow:0 8px 24px rgba(0,0,0,.2);display:inline-flex;align-items:center;gap:8px}
  .lt-mask rect{ } /* spotlight is an SVG mask (supports multiple highlights) */
</style>
<script>
(function(){
  var KEY='aiom_leadtour_v1_<?php echo (int)$userId; ?>';
  // Server-side, per-account "has seen the tour" flag — the source of truth so the
  // walkthrough follows the account across browsers/devices, not just this browser.
  var SERVER_SEEN=<?php echo $tourSeen ? 'true' : 'false'; ?>;
  function persistSeen(){ try{ fetch('leadlists.php?action=markTourSeen',{method:'POST',headers:{'Content-Type':'application/json'},body:'{}'}); }catch(e){} }
  function qs(s){ try{ return document.querySelector(s); }catch(e){ return null; } }
  function vis(el){ if(!el) return false; var r=el.getClientRects(); if(!r.length) return false; var cs=window.getComputedStyle(el); return cs.visibility!=='hidden' && cs.display!=='none' && r[0].width>0 && r[0].height>0; }
  function firstVisible(sel){ var e=document.querySelectorAll(sel); for(var k=0;k<e.length;k++){ if(vis(e[k])) return e[k]; } return null; }

  var steps=[
    {title:'Create a list',
     text:'Your leads live inside lists. Click <b>New List</b> to make your first one.',
     target:function(){return firstVisible('[onclick*="openCreateModal"]');},
     advance:function(){var m=qs('#createModal');return !!(m&&m.classList.contains('active'));}},
    {title:'Name it, then Create',
     text:'Give your list a name like &ldquo;Dentists in Texas&rdquo;, then click <b>Create</b> &mdash; we&rsquo;ll drop you right into it.',
     target:function(){return [qs('#listName'), firstVisible('#createModal .btn-primary')];},
     advance:function(){var m=qs('#createModal');return !(m&&m.classList.contains('active'));}},
    {title:'Add leads',
     text:'You&rsquo;re inside your new list. Click <b>Add Leads</b> to search Google Maps for businesses.',
     target:function(){return firstVisible('[onclick*="openAddLeadsModal"]');},
     advance:function(){var m=qs('#addLeadsModal');return !!(m&&m.classList.contains('active'));}},
    {title:'Type what you sell to',
     text:'Enter the kind of business you want &mdash; e.g. <b>&ldquo;dentists&rdquo;</b> or &ldquo;roofers&rdquo;.',
     target:function(){return vis(qs('#scrapeQuery'))?qs('#scrapeQuery'):qs('#addLeadsModal');},
     next:true},
    {title:'Results per city',
     text:'Choose how many businesses to pull from each city. Start small &mdash; it&rsquo;s 1 credit per lead returned.',
     target:function(){return vis(qs('#scrapeLimit'))?qs('#scrapeLimit'):qs('#addLeadsModal');},
     next:true},
    {title:'Pick a country',
     text:'Choose the country to search &mdash; United States, United Kingdom or Europe.',
     target:function(){return vis(qs('#countryPicker'))?qs('#countryPicker'):qs('#addLeadsModal');},
     next:true},
    {title:'Choose your states',
     text:'Tick the states (or regions) you want to search in.',
     target:function(){return firstVisible('.selector-grid .selector-panel:first-child');},
     next:true},
    {title:'Choose your cities',
     text:'Pick the cities to pull leads from &mdash; selecting a state fills this list.',
     target:function(){return firstVisible('.selector-grid .selector-panel:last-child');},
     next:true},
    {title:'Start the search',
     text:'When you&rsquo;re ready, hit <b>Start Search</b> &mdash; we pull every matching business (name, phone, website, rating) into your list.',
     target:function(){return vis(qs('#startScrapeBtn'))?qs('#startScrapeBtn'):qs('#addLeadsModal');},
     advance:function(){var p=qs('#scrapeProgress');return vis(p);}},
    {title:'Work your leads',
     text:'Your leads land right here in your list. <b>Enrich</b> them with emails &amp; socials for free anytime. When the search finishes, close this window to see them all.',
     target:function(){return null;},next:true,last:true}
  ];

  var SVGNS='http://www.w3.org/2000/svg';
  var i=0, mask=null, tip=null, timer=null;
  function mk(cls){ var d=document.createElement('div'); d.className=cls; return d; }
  function build(){
    mask=document.createElementNS(SVGNS,'svg'); mask.setAttribute('class','lt-mask'); mask.style.display='none';
    document.body.appendChild(mask);
    tip=mk('lt-tip'); document.body.appendChild(tip);
  }

  function targets(){
    var s=steps[i]; var t; try{ t=s.target&&s.target(); }catch(e){ t=null; }
    if(!t) return [];
    if(!Array.isArray(t)) t=[t];
    return t.filter(function(el){ return el && vis(el); });
  }

  function drawMask(rects){
    var W=window.innerWidth, H=window.innerHeight;
    var holes=rects.map(function(r){ return '<rect x="'+r.x+'" y="'+r.y+'" width="'+r.w+'" height="'+r.h+'" rx="10" ry="10" fill="#000"/>'; }).join('');
    mask.setAttribute('width',W); mask.setAttribute('height',H); mask.setAttribute('viewBox','0 0 '+W+' '+H);
    mask.innerHTML='<defs><mask id="ltHole"><rect x="0" y="0" width="'+W+'" height="'+H+'" fill="#fff"/>'+holes+'</mask></defs>'+
      '<rect x="0" y="0" width="'+W+'" height="'+H+'" fill="rgba(12,15,18,0.55)" mask="url(#ltHole)"/>';
  }

  function render(){
    var s=steps[i]; if(!s){ finish(); return; }
    var actions='';
    if(i>0) actions+='<button class="lt-btn lt-back" data-a="back">Back</button>';
    if(s.next||s.last) actions+='<button class="lt-btn lt-next" data-a="next">'+(s.last?'Got it':'Next')+'</button>';
    tip.innerHTML='<div class="lt-badge">Step '+(i+1)+' of '+steps.length+'</div><h4>'+s.title+'</h4><p>'+(s.text||'')+'</p>'+
      '<div class="lt-row"><button class="lt-skip" data-a="skip">Skip tour</button><div class="lt-actions">'+actions+'</div></div>';
    Array.prototype.forEach.call(tip.querySelectorAll('[data-a]'),function(b){
      b.addEventListener('click',function(){var a=b.getAttribute('data-a');
        if(a==='skip'){finish();} else if(a==='back'){go(i-1);} else if(a==='next'){ if(s.last){finish();}else{go(i+1);} }});
    });
    tip.style.display='block';
    scrollToTarget();
    position();
  }

  function scrollToTarget(){
    var els=targets(); if(els.length){ try{ els[0].scrollIntoView({block:'center',behavior:'smooth'}); }catch(e){} }
  }

  function position(){
    if(!tip) return;
    var els=targets(), pad=6;
    if(els.length){
      var rects=els.map(function(el){ var r=el.getBoundingClientRect(); return {x:r.left-pad,y:r.top-pad,w:r.width+pad*2,h:r.height+pad*2}; });
      mask.style.display='block'; drawMask(rects);
      var ar=els[els.length-1].getBoundingClientRect();
      var th=tip.offsetHeight||150, tw=tip.offsetWidth||300;
      var top=ar.bottom+12; if(top+th>window.innerHeight-8){ top=Math.max(8,ar.top-12-th); }
      var left=Math.min(Math.max(8,ar.left),window.innerWidth-tw-8);
      tip.style.left=left+'px'; tip.style.top=top+'px';
    } else {
      mask.style.display='none';
      var tw2=tip.offsetWidth||300, th2=tip.offsetHeight||150;
      tip.style.left=Math.max(8,(window.innerWidth-tw2)/2)+'px';
      tip.style.top=Math.max(8,(window.innerHeight-th2)/2)+'px';
    }
  }

  function go(n){ i=Math.max(0,Math.min(steps.length-1,n)); render(); }

  function loop(){
    if(!tip) return;
    position();
    var s=steps[i];
    if(s&&s.advance){ try{ if(s.advance()&&i<steps.length-1){ go(i+1); } }catch(e){} }
  }

  function start(){ if(!mask){ build(); } i=0; render(); if(timer){ clearInterval(timer); } timer=setInterval(loop,300); }
  function finish(){ if(timer){ clearInterval(timer); timer=null; } if(tip){ tip.style.display='none'; } if(mask){ mask.style.display='none'; } try{ localStorage.setItem(KEY,'1'); }catch(e){} persistSeen(); }

  // Restart the whole walkthrough: close any modal, return to the lists grid so
  // step 1 makes sense, then start from the top.
  function outOfCredits(){ return !!(window.app && typeof app.credits!=='undefined' && app.credits < 1); }
  function restartTour(){
    // Out of credits: show the upgrade prompt instead of a tour they can't act on.
    if(outOfCredits()){ if(window.app && typeof app.showUpgradePrompt==='function'){ app.showUpgradePrompt(1); } return; }
    try{
      var cm=qs('#createModal'); if(cm){ cm.classList.remove('active'); }
      var am=qs('#addLeadsModal'); if(am){ am.classList.remove('active'); }
      var dv=qs('#detailView'), lv=qs('#listsView');
      if(dv && !dv.classList.contains('hidden')){
        var done=false;
        if(window.app && typeof app.goBack==='function'){ try{ app.goBack(); done=true; }catch(e){} }
        if(!done && lv){ dv.classList.add('hidden'); lv.classList.remove('hidden'); } // fallback: toggle views directly
      }
    }catch(e){}
    setTimeout(start,120);
  }
  function addHelp(){ var h=mk('lt-help'); h.innerHTML='<i class="fas fa-circle-question"></i> How to pull leads'; h.addEventListener('click',restartTour); document.body.appendChild(h); }

  // One-off follow-up tip on the Export button, shown once leads have populated.
  var EKEY='aiom_exporttip_v1_<?php echo (int)$userId; ?>';
  function exportTip(){
    var btn=firstVisible('#exportMenuBtn'); if(!btn) return;
    var em=document.createElementNS(SVGNS,'svg'); em.setAttribute('class','lt-mask'); document.body.appendChild(em);
    var et=mk('lt-tip'); document.body.appendChild(et);
    et.innerHTML='<div class="lt-badge">You&rsquo;ve got leads!</div><h4>Get your leads out</h4>'+
      '<p>Click <b>Export</b> to download a <b>CSV</b>, or <b>connect your Free CRM</b> to push your leads straight into your CRM.</p>'+
      '<div class="lt-row"><span></span><div class="lt-actions"><button class="lt-btn lt-next" data-a="ok">Got it</button></div></div>';
    function place(){
      var r=btn.getBoundingClientRect(), pad=6, W=window.innerWidth, H=window.innerHeight;
      em.setAttribute('width',W); em.setAttribute('height',H); em.setAttribute('viewBox','0 0 '+W+' '+H);
      em.innerHTML='<defs><mask id="ltHoleE"><rect width="'+W+'" height="'+H+'" fill="#fff"/>'+
        '<rect x="'+(r.left-pad)+'" y="'+(r.top-pad)+'" width="'+(r.width+pad*2)+'" height="'+(r.height+pad*2)+'" rx="10" fill="#000"/></mask></defs>'+
        '<rect width="'+W+'" height="'+H+'" fill="rgba(12,15,18,0.55)" mask="url(#ltHoleE)"/>';
      var th=et.offsetHeight||150, tw=et.offsetWidth||300;
      var top=r.bottom+12; if(top+th>H-8){ top=Math.max(8,r.top-12-th); }
      var left=Math.min(Math.max(8,r.right-tw),W-tw-8);
      et.style.left=left+'px'; et.style.top=top+'px';
    }
    place(); et.style.display='block'; em.style.display='block';
    var iv=setInterval(place,300);
    et.querySelector('[data-a="ok"]').addEventListener('click',function(){
      clearInterval(iv); if(et.parentNode)et.remove(); if(em.parentNode)em.remove();
      try{ localStorage.setItem(EKEY,'1'); }catch(e){}
      var sc=null; try{ sc=localStorage.getItem(CKEY); }catch(e){}
      if(!sc){ cheaperTip(); }   // then nudge them toward cheaper leads
    });
  }

  // Final upsell nudge (shown once, after the export tip): forces them to the
  // "Get Leads For Less Than 1c" page.
  var CKEY='aiom_cheaptip_v1_<?php echo (int)$userId; ?>';
  function cheaperTip(){
    var cm=document.createElementNS(SVGNS,'svg'); cm.setAttribute('class','lt-mask'); document.body.appendChild(cm);
    var ct=mk('lt-tip'); document.body.appendChild(ct);
    ct.innerHTML='<div class="lt-badge">One more thing</div><h4>Get leads even cheaper &mdash; less than 1&cent;!</h4>'+
      '<p>Want leads for a fraction of a penny each? Own the software, pull leads at cost and even resell it for profit. See how in the <b>Get Leads For Less Than 1&cent;</b> tab.</p>'+
      '<div class="lt-row"><span></span><div class="lt-actions"><button class="lt-btn lt-next" data-a="go">Show me how &rarr;</button></div></div>';
    function place(){
      var W=window.innerWidth, H=window.innerHeight;
      cm.setAttribute('width',W); cm.setAttribute('height',H); cm.setAttribute('viewBox','0 0 '+W+' '+H);
      cm.innerHTML='<rect width="'+W+'" height="'+H+'" fill="rgba(12,15,18,0.55)"/>';
      var tw=ct.offsetWidth||330, th=ct.offsetHeight||190;
      ct.style.left=Math.max(8,(W-tw)/2)+'px'; ct.style.top=Math.max(8,(H-th)/2)+'px';
    }
    place(); ct.style.display='block'; cm.style.display='block';
    var iv=setInterval(place,300);
    ct.querySelector('[data-a="go"]').addEventListener('click',function(){
      clearInterval(iv); try{ localStorage.setItem(CKEY,'1'); }catch(e){}
      try{ (window.top||window).location.href='dashboard.php?section=penny'; }catch(e){ try{ location.href='dashboard.php?section=penny'; }catch(_){} }
    });
  }

  function watchExport(){
    var e=null,c=null; try{ e=localStorage.getItem(EKEY); c=localStorage.getItem(CKEY); }catch(_){}
    if(e && c){ return; }
    var iv=setInterval(function(){
      if(tip && tip.style.display!=='none'){ return; }                                   // main tour still open
      var am=qs('#addLeadsModal'); if(am && am.classList.contains('active')){ return; }   // wait until the search window is closed
      var body=qs('#leadsBody');
      if(body && body.querySelector('tr') && firstVisible('#exportMenuBtn')){
        clearInterval(iv);
        var e2=null,c2=null; try{ e2=localStorage.getItem(EKEY); c2=localStorage.getItem(CKEY); }catch(_){}
        if(!e2){ exportTip(); } else if(!c2){ cheaperTip(); }
      }
    },800);
  }

  function init(){
    addHelp();
    watchExport();
    // Source of truth is the per-account server flag (follows the account across
    // browsers/devices). localStorage is only a same-session fallback cache.
    if(SERVER_SEEN){ return; }
    var seen=null; try{ seen=localStorage.getItem(KEY); }catch(e){}
    if(seen){ return; }
    // Auto-start for first-time users. Prefer starting once the "New List" entry
    // point is on screen, but start anyway after a few seconds — step 1 will
    // snap onto the button as soon as it renders (never leaves new users hanging).
    var tries=0, wait=setInterval(function(){
      tries++;
      if(outOfCredits()){ clearInterval(wait); return; }   // out of credits — don't auto-run the tour
      if(firstVisible('[onclick*="openCreateModal"]')){ clearInterval(wait); start(); }
      else if(tries>=20){ clearInterval(wait); start(); }
    },300);
  }
  if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',function(){ setTimeout(init,400); }); }
  else { setTimeout(init,400); }
})();
</script>

<?php // $showPennyPromo was computed near the top (page-render path) before the session lock was released. ?>
<?php if ($showPennyPromo): ?>
<div id="pennyPromo" class="pp-ov" role="dialog" aria-modal="true" aria-labelledby="ppTitle">
  <div class="pp-card">
    <button class="pp-x" type="button" aria-label="Close">&times;</button>
    <div class="pp-badge"><i class="fas fa-bolt"></i> Insider offer</div>
    <h3 id="ppTitle">Get leads for <span>less than 1&cent;</span> each</h3>
    <p>Own this exact software, pull leads at cost, and even resell it to your own clients for profit &mdash; a whole new revenue stream.</p>
    <button class="pp-cta" type="button" id="ppGo">Show me how &rarr;</button>
    <button class="pp-later" type="button" id="ppLater">Maybe later</button>
  </div>
</div>
<style>
  .pp-ov{position:fixed;inset:0;z-index:100000;background:rgba(12,15,18,.55);display:flex;align-items:center;justify-content:center;padding:20px;-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px)}
  .pp-card{background:#fff;border-radius:20px;max-width:420px;width:100%;padding:32px 28px 24px;text-align:center;box-shadow:0 30px 80px rgba(10,15,25,.4);position:relative;animation:ppin .22s ease}
  @keyframes ppin{from{transform:translateY(12px) scale(.98);opacity:0}to{transform:none;opacity:1}}
  .pp-x{position:absolute;top:12px;right:14px;border:none;background:transparent;font-size:26px;line-height:1;color:#98a0a8;cursor:pointer;padding:4px}
  .pp-x:hover{color:#5b6066}
  .pp-badge{display:inline-flex;align-items:center;gap:7px;background:#fee2e2;color:#b91c1c;font-weight:800;font-size:12px;letter-spacing:.03em;text-transform:uppercase;padding:7px 14px;border-radius:999px;margin-bottom:14px}
  .pp-card h3{font-size:24px;font-weight:900;letter-spacing:-.02em;color:#141517;line-height:1.15}
  .pp-card h3 span{color:#dc2626}
  .pp-card p{font-size:14.5px;color:#5b6066;line-height:1.6;margin:12px auto 22px;max-width:34ch}
  .pp-cta{display:block;width:100%;background:#dc2626;color:#fff;font-weight:800;font-size:16px;border:none;border-radius:12px;padding:15px;cursor:pointer;box-shadow:0 10px 26px rgba(220,38,38,.32);font-family:inherit}
  .pp-cta:hover{background:#b91c1c}
  .pp-later{display:block;width:100%;background:transparent;color:#7a8088;font-weight:700;font-size:13px;border:none;padding:12px 0 2px;cursor:pointer;font-family:inherit}
  .pp-later:hover{color:#5b6066}
</style>
<script>
(function(){
  var ov=document.getElementById('pennyPromo'); if(!ov) return;
  function close(){ ov.style.display='none'; }
  ov.querySelector('.pp-x').addEventListener('click',close);
  document.getElementById('ppLater').addEventListener('click',close);
  document.getElementById('ppGo').addEventListener('click',function(){ (window.top||window).location.href='dashboard.php?section=penny'; });
  ov.addEventListener('click',function(e){ if(e.target===ov) close(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') close(); });
})();
</script>
<?php endif; ?>
</body>
</html>
