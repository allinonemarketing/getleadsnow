<?php
session_start();
@set_time_limit(0);
@ini_set('memory_limit', '512M');

$baseDir = __DIR__;
$envFile = $baseDir . '/.env';
if (!file_exists($envFile)) {
    die('No .env file found. Run install.php first.');
}

require_once __DIR__ . '/includes/auth.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
$curUser = getCurrentUser();
$adminStmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
$adminStmt->execute([$curUser['id']]);
if (!$adminStmt->fetchColumn()) {
    http_response_code(403);
    die('Admin access required.');
}

$dbName = env('DB_NAME', '');

$datasets = [
    [
        'label' => 'USA — States, Cities & Towns',
        'file' => 'usa_all_states_cities.sql',
        'table' => 'usa_all_states_cities_full_hd2data',
        'expected' => 36172,
    ],
    [
        'label' => 'Europe — 44 Countries Cities & Towns',
        'file' => 'european_cities_towns.sql',
        'table' => 'european_cities_towns_44_countries_hd2data',
        'expected' => 57940,
    ],
    [
        'label' => 'Europe — Cities (fallback)',
        'file' => 'european_cities.sql',
        'table' => 'european_cities',
        'expected' => 437,
    ],
    [
        'label' => 'United Kingdom — Cities',
        'file' => 'ukcities.sql',
        'table' => 'ukcities',
        'expected' => 5592,
    ],
];

function tableRowCount(PDO $pdo, $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
}

function loadSqlFile(PDO $pdo, $path, $table) {
    if (!file_exists($path)) {
        return ['ok' => false, 'error' => "File not found: $path"];
    }

    try {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'DROP failed: ' . $e->getMessage()];
    }

    $fh = fopen($path, 'r');
    if (!$fh) return ['ok' => false, 'error' => 'Cannot open file'];

    $buffer = '';
    $executed = 0;
    $batch = '';
    $batchCount = 0;
    $batchLimit = 500;

    try {
        while (($line = fgets($fh)) !== false) {
            $trim = trim($line);
            if ($trim === '' || strpos($trim, '--') === 0) continue;
            $buffer .= $line;
            if (substr($trim, -1) === ';') {
                $batch .= $buffer;
                $batchCount++;
                $buffer = '';
                if ($batchCount >= $batchLimit) {
                    $pdo->exec($batch);
                    $executed += $batchCount;
                    $batch = '';
                    $batchCount = 0;
                }
            }
        }
        if ($batchCount > 0) {
            $pdo->exec($batch);
            $executed += $batchCount;
        }
    } catch (PDOException $e) {
        fclose($fh);
        return ['ok' => false, 'error' => 'SQL error: ' . $e->getMessage(), 'executed' => $executed];
    }

    fclose($fh);
    return ['ok' => true, 'executed' => $executed];
}

$message = null;
$messageType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    if ($_POST['action'] === 'load' && !empty($_POST['file'])) {
        $target = null;
        foreach ($datasets as $d) {
            if ($d['file'] === $_POST['file']) { $target = $d; break; }
        }
        if ($target) {
            $result = loadSqlFile($pdo, $baseDir . '/' . $target['file'], $target['table']);
            if ($result['ok']) {
                $rows = tableRowCount($pdo, $target['table']);
                $message = "Loaded {$target['file']} — $rows rows in `{$target['table']}`.";
                $messageType = 'success';
            } else {
                $message = "Failed to load {$target['file']}: " . $result['error'];
                $messageType = 'error';
            }
        }
    } elseif ($_POST['action'] === 'load_all') {
        $logs = [];
        foreach ($datasets as $d) {
            $path = $baseDir . '/' . $d['file'];
            $current = tableRowCount($pdo, $d['table']);
            if ($current !== null && $current >= $d['expected']) {
                $logs[] = "✓ {$d['file']} — already complete ($current rows)";
                continue;
            }
            $result = loadSqlFile($pdo, $path, $d['table']);
            if ($result['ok']) {
                $rows = tableRowCount($pdo, $d['table']);
                $logs[] = "✓ {$d['file']} — loaded ($rows rows)";
            } else {
                $logs[] = "✗ {$d['file']} — " . $result['error'];
            }
        }
        $message = implode("\n", $logs);
        $messageType = 'success';
    }
}

$status = [];
foreach ($datasets as $d) {
    $rows = tableRowCount($pdo, $d['table']);
    $fileExists = file_exists($baseDir . '/' . $d['file']);
    if ($rows === null) {
        $state = 'missing';
    } elseif ($rows === 0) {
        $state = 'empty';
    } elseif ($rows < $d['expected'] * 0.95) {
        $state = 'partial';
    } else {
        $state = 'ok';
    }
    $status[] = array_merge($d, ['rows' => $rows, 'state' => $state, 'file_exists' => $fileExists]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>City Database Check</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --accent: #c85719;
            --bg: #f5f5f7;
            --card: #fff;
            --border: rgba(0,0,0,0.08);
            --text: #1d1d1f;
            --text-secondary: #86868b;
            --green: #34C759;
            --orange: #FF9500;
            --red: #FF3B30;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: var(--bg); min-height: 100vh; padding: 40px 20px; color: var(--text); }
        .wrap { max-width: 900px; margin: 0 auto; }
        .header { margin-bottom: 24px; }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 6px; }
        .header p { color: var(--text-secondary); font-size: 14px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.03); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; font-size: 14px; border-bottom: 1px solid var(--border); }
        th { background: var(--bg); font-weight: 600; font-size: 12px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.4px; }
        tr:last-child td { border-bottom: none; }
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
        .badge.ok { background: #eafbe7; color: var(--green); }
        .badge.partial { background: #fff4e0; color: var(--orange); }
        .badge.missing, .badge.empty { background: #ffe5e3; color: var(--red); }
        .mono { font-family: 'SF Mono', Menlo, monospace; font-size: 12px; color: var(--text-secondary); }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid var(--border); background: #fff; color: var(--text); border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; font-family: inherit; transition: background 0.15s; }
        .btn:hover { background: var(--bg); }
        .btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
        .btn.primary:hover { opacity: 0.9; background: var(--accent); }
        .btn[disabled] { opacity: 0.5; cursor: not-allowed; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; font-size: 14px; white-space: pre-wrap; line-height: 1.55; }
        .alert.success { background: #eafbe7; color: #1f7a36; border: 1px solid #b7e6bf; }
        .alert.error { background: #ffe5e3; color: #b3261e; border: 1px solid #f3b8b3; }
        .back { color: var(--text-secondary); text-decoration: none; font-size: 13px; }
        .back:hover { color: var(--accent); }
    </style>
</head>
<body>
<div class="wrap">
    <a href="leadlists.php" class="back"><i class="fas fa-arrow-left"></i> Back to app</a>
    <div class="header" style="margin-top:10px;">
        <h1>City Database Check</h1>
        <p>Verify every city dataset is fully loaded. Reload any dataset that's missing or incomplete.</p>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <div class="toolbar">
        <div class="mono">Database: <?= htmlspecialchars($dbName) ?></div>
        <form method="POST" onsubmit="return confirm('Reload all incomplete datasets? This drops and rebuilds the affected tables.');">
            <input type="hidden" name="action" value="load_all">
            <button class="btn primary" type="submit"><i class="fas fa-bolt"></i> Load all missing / incomplete</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Dataset</th>
                    <th>Table</th>
                    <th style="text-align:right;">Rows</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($status as $s): ?>
                <tr>
                    <td>
                        <div style="font-weight:600;"><?= htmlspecialchars($s['label']) ?></div>
                        <div class="mono"><?= htmlspecialchars($s['file']) ?> <?php if (!$s['file_exists']): ?><span style="color:var(--red);">(file missing!)</span><?php endif; ?></div>
                    </td>
                    <td class="mono"><?= htmlspecialchars($s['table']) ?></td>
                    <td style="text-align:right;" class="mono">
                        <?= $s['rows'] === null ? '—' : number_format($s['rows']) ?>
                        <div style="color:var(--text-secondary);font-size:11px;">expected ≈ <?= number_format($s['expected']) ?></div>
                    </td>
                    <td>
                        <?php if ($s['state'] === 'ok'): ?>
                            <span class="badge ok"><i class="fas fa-check"></i> OK</span>
                        <?php elseif ($s['state'] === 'partial'): ?>
                            <span class="badge partial"><i class="fas fa-triangle-exclamation"></i> Partial</span>
                        <?php elseif ($s['state'] === 'empty'): ?>
                            <span class="badge empty"><i class="fas fa-circle-exclamation"></i> Empty</span>
                        <?php else: ?>
                            <span class="badge missing"><i class="fas fa-circle-xmark"></i> Missing</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Drop and reload <?= htmlspecialchars($s['table']) ?>?');">
                            <input type="hidden" name="action" value="load">
                            <input type="hidden" name="file" value="<?= htmlspecialchars($s['file']) ?>">
                            <button class="btn" type="submit" <?= $s['file_exists'] ? '' : 'disabled' ?>>
                                <i class="fas fa-rotate"></i> <?= $s['state'] === 'ok' ? 'Reload' : 'Load' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <p style="color:var(--text-secondary);font-size:12px;margin-top:14px;">
        Loading the larger datasets can take 30–90 seconds. Don't close the page until you see a result.
    </p>
</div>
</body>
</html>
