<?php
session_start();
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = getCurrentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            try {
                $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
                $apiKey = bin2hex(random_bytes(32));
                
                $stmt = $pdo->prepare("INSERT INTO api_keys (user_id, name, api_key) VALUES (?, ?, ?)");
                $stmt->execute([$_SESSION['user_id'], $name, $apiKey]);
                
                echo json_encode(['success' => true, 'key' => $apiKey]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'update':
            try {
                $id = filter_var($_POST['id'], FILTER_SANITIZE_NUMBER_INT);
                $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
                
                $stmt = $pdo->prepare("UPDATE api_keys SET name = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$name, $id, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'delete':
            try {
                $id = filter_var($_POST['id'], FILTER_SANITIZE_NUMBER_INT);
                
                $stmt = $pdo->prepare("DELETE FROM api_keys WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $_SESSION['user_id']]);
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
            
        case 'get_key':
            try {
                $id = filter_var($_POST['id'], FILTER_SANITIZE_NUMBER_INT);
                
                $stmt = $pdo->prepare("SELECT api_key FROM api_keys WHERE id = ? AND user_id = ?");
                $stmt->execute([$id, $_SESSION['user_id']]);
                $key = $stmt->fetchColumn();
                
                if ($key) {
                    echo json_encode(['success' => true, 'key' => $key]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Key not found']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;
    }
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, name, api_key, credits_used, created_at, last_used_at, is_active 
    FROM api_keys 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$stmt->execute([$_SESSION['user_id']]);
$apiKeys = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Keys Management - <?= APP_NAME ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Orbitron', sans-serif;
        }

        body {
            background: linear-gradient(to bottom, #000033, #000066);
            color: #0ff;
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .create-key-btn {
            background: linear-gradient(to right, #0ff, #00ccff);
            color: #000033;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.3s ease;
        }

        .create-key-btn:hover {
            box-shadow: 0 0 20px #0ff;
        }

        .api-keys-grid {
            display: grid;
            gap: 1.5rem;
        }

        .api-key-card {
            background: rgba(0, 0, 51, 0.7);
            padding: 1.5rem;
            border-radius: 15px;
            box-shadow: 0 0 15px rgba(0, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .api-key-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .api-key-name {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .api-key-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            background: none;
            border: 1px solid #0ff;
            color: #0ff;
            padding: 0.5rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: rgba(0, 255, 255, 0.1);
            box-shadow: 0 0 10px #0ff;
        }

        .api-key-details {
            display: grid;
            gap: 0.5rem;
        }

        .api-key-value {
            background: rgba(0, 0, 102, 0.5);
            padding: 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            word-break: break-all;
        }

        .stats {
            display: flex;
            gap: 2rem;
            margin-top: 1rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 51, 0.9);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(0, 0, 51, 0.95);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0, 255, 255, 0.3);
            width: 90%;
            max-width: 500px;
        }

        .modal input {
            width: 100%;
            padding: 0.8rem;
            margin: 1rem 0;
            background: rgba(0, 0, 102, 0.5);
            border: 2px solid #0ff;
            border-radius: 10px;
            color: #0ff;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .api-key-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .api-key-value {
            position: relative;
            flex-grow: 1;
            background: rgba(0, 0, 102, 0.5);
            padding: 0.5rem;
            border-radius: 5px;
            font-family: monospace;
            word-break: break-all;
        }

        .copy-btn, .toggle-visibility-btn {
            background: none;
            border: 1px solid #0ff;
            color: #0ff;
            padding: 0.5rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .copy-btn {
            min-width: 100px;
        }

        .copy-btn:hover, .toggle-visibility-btn:hover {
            background: rgba(0, 255, 255, 0.1);
            box-shadow: 0 0 10px #0ff;
        }

        .copy-btn.copied {
            background: #0ff;
            color: #000033;
        }

        .eye-icon {
            width: 20px;
            height: 20px;
            fill: currentColor;
        }

        .eye-icon .eye-open,
        .eye-icon .eye-closed {
            transition: opacity 0.3s ease;
        }

        .eye-icon.hidden .eye-open {
            display: none;
        }

        .eye-icon.hidden .eye-closed {
            display: block;
        }

        .eye-icon .eye-open {
            display: block;
        }

        .eye-icon .eye-closed {
            display: none;
        }

        .eye-icon.hidden {
            color: rgba(0, 255, 255, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>API Keys Management</h1>
            <button class="create-key-btn" onclick="showCreateModal()">Create New API Key</button>
        </div>

        <div class="api-keys-grid">
            <?php foreach ($apiKeys as $key): ?>
                <div class="api-key-card">
                    <div class="api-key-header">
                        <div class="api-key-name"><?= htmlspecialchars($key['name']) ?></div>
                        <div class="api-key-actions">
                            <button class="action-btn" onclick="showEditModal(<?= $key['id'] ?>, '<?= htmlspecialchars($key['name']) ?>')">Edit</button>
                            <button class="action-btn" onclick="deleteApiKey(<?= $key['id'] ?>)">Delete</button>
                        </div>
                    </div>
                    <div class="api-key-details">
                        <div class="api-key-container">
                            <div class="api-key-value">
                                <span id="key-text-<?= $key['id'] ?>">
                                    <?= substr($key['api_key'], 0, 12) ?>...
                                </span>
                            </div>
                            <button class="toggle-visibility-btn" onclick="toggleKeyVisibility(this, <?= $key['id'] ?>)" title="Show/Hide API Key">
                                <svg class="eye-icon hidden" viewBox="0 0 24 24">
                                    <path class="eye-open" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                                    <path class="eye-closed" d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
                                </svg>
                            </button>
                            <button class="copy-btn" data-id="<?= $key['id'] ?>" onclick="copyApiKey(this)">
                                Copy Key
                            </button>
                        </div>
                        <div class="stats">
                            <div>Credits Used: <?= number_format($key['credits_used']) ?></div>
                            <div>Created: <?= date('Y-m-d', strtotime($key['created_at'])) ?></div>
                            <?php if ($key['last_used_at']): ?>
                                <div>Last Used: <?= date('Y-m-d', strtotime($key['last_used_at'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="createModal" class="modal">
        <div class="modal-content">
            <h2>Create New API Key</h2>
            <input type="text" id="newKeyName" placeholder="Enter API Key Name">
            <div class="modal-buttons">
                <button class="action-btn" onclick="hideModal('createModal')">Cancel</button>
                <button class="create-key-btn" onclick="createApiKey()">Create</button>
            </div>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit API Key</h2>
            <input type="text" id="editKeyName" placeholder="Enter New Name">
            <input type="hidden" id="editKeyId">
            <div class="modal-buttons">
                <button class="action-btn" onclick="hideModal('editModal')">Cancel</button>
                <button class="create-key-btn" onclick="updateApiKey()">Update</button>
            </div>
        </div>
    </div>

    <script>
        function showCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }

        function showEditModal(id, name) {
            document.getElementById('editKeyId').value = id;
            document.getElementById('editKeyName').value = name;
            document.getElementById('editModal').style.display = 'flex';
        }

        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function createApiKey() {
            const name = document.getElementById('newKeyName').value;
            if (!name) return;

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('name', name);

            fetch('api_keys.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function updateApiKey() {
            const id = document.getElementById('editKeyId').value;
            const name = document.getElementById('editKeyName').value;
            if (!name) return;

            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('name', name);

            fetch('api_keys.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function deleteApiKey(id) {
            if (!confirm('Are you sure you want to delete this API key?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('api_keys.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        function toggleKeyVisibility(button, keyId) {
            const keySpan = document.getElementById(`key-text-${keyId}`);
            const eyeIcon = button.querySelector('.eye-icon');
            
            if (eyeIcon.classList.contains('hidden')) {
                const formData = new FormData();
                formData.append('action', 'get_key');
                formData.append('id', keyId);

                fetch('api_keys.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        keySpan.textContent = data.key;
                        eyeIcon.classList.remove('hidden');
                        
                        setTimeout(() => {
                            keySpan.textContent = data.key.substring(0, 12) + '...';
                            eyeIcon.classList.add('hidden');
                        }, 30000);
                    }
                });
            } else {
                keySpan.textContent = keySpan.textContent.substring(0, 12) + '...';
                eyeIcon.classList.add('hidden');
            }
        }

        function copyApiKey(button) {
            const keyId = button.getAttribute('data-id');
            
            const formData = new FormData();
            formData.append('action', 'get_key');
            formData.append('id', keyId);

            fetch('api_keys.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const textarea = document.createElement('textarea');
                    textarea.value = data.key;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                    
                    button.textContent = 'Copied!';
                    button.classList.add('copied');
                    
                    setTimeout(() => {
                        button.textContent = 'Copy Key';
                        button.classList.remove('copied');
                    }, 2000);
                }
            });
        }
    </script>
</body>
</html>
