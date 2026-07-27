<?php
session_start();
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$isAdmin = $stmt->fetchColumn();

if (!$isAdmin) {
    header('Location: dashboard.php');
    exit();
}

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'api_endpoints'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_endpoints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            description TEXT,
            base_url VARCHAR(255) NOT NULL,
            method ENUM('GET', 'POST', 'PUT', 'DELETE') NOT NULL DEFAULT 'GET',
            parameters JSON,
            required_parameters JSON,
            headers JSON,
            credits_per_call INT NOT NULL DEFAULT 1,
            is_active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (endpoint)
        )");
    }
} catch (Exception $e) {
    error_log("Error checking/creating api_endpoints table: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'create':
                $name = $_POST['name'] ?? '';
                $endpoint = $_POST['endpoint'] ?? '';
                $description = $_POST['description'] ?? '';
                $baseUrl = $_POST['base_url'] ?? '';
                $method = $_POST['method'] ?? 'GET';
                $parameters = json_encode($_POST['parameters'] ?? []);
                $requiredParameters = json_encode($_POST['required_parameters'] ?? []);
                $headers = json_encode($_POST['headers'] ?? []);
                $creditsPerCall = intval($_POST['credits_per_call'] ?? 1);
                
                $stmt = $pdo->prepare("INSERT INTO api_endpoints (name, endpoint, description, base_url, method, parameters, required_parameters, headers, credits_per_call) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $endpoint, $description, $baseUrl, $method, $parameters, $requiredParameters, $headers, $creditsPerCall]);
                
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                exit;
                
            case 'update':
                $id = intval($_POST['id'] ?? 0);
                $name = $_POST['name'] ?? '';
                $endpoint = $_POST['endpoint'] ?? '';
                $description = $_POST['description'] ?? '';
                $baseUrl = $_POST['base_url'] ?? '';
                $method = $_POST['method'] ?? 'GET';
                $parameters = json_encode($_POST['parameters'] ?? []);
                $requiredParameters = json_encode($_POST['required_parameters'] ?? []);
                $headers = json_encode($_POST['headers'] ?? []);
                $creditsPerCall = intval($_POST['credits_per_call'] ?? 1);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                $stmt = $pdo->prepare("UPDATE api_endpoints SET name = ?, endpoint = ?, description = ?, base_url = ?, method = ?, parameters = ?, required_parameters = ?, headers = ?, credits_per_call = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $endpoint, $description, $baseUrl, $method, $parameters, $requiredParameters, $headers, $creditsPerCall, $isActive, $id]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'delete':
                $id = intval($_POST['id'] ?? 0);
                
                $stmt = $pdo->prepare("DELETE FROM api_endpoints WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true]);
                exit;
                
            case 'test':
                $id = intval($_POST['id'] ?? 0);
                $testParams = $_POST['test_params'] ?? [];
                
                $stmt = $pdo->prepare("SELECT * FROM api_endpoints WHERE id = ?");
                $stmt->execute([$id]);
                $endpoint = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$endpoint) {
                    throw new Exception("API endpoint not found");
                }
                
                $url = $endpoint['base_url'];
                if ($endpoint['method'] === 'GET' && !empty($testParams)) {
                    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($testParams);
                }
                
                $ch = curl_init();
                
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 12000);
                
                if ($endpoint['method'] !== 'GET') {
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $endpoint['method']);
                    
                    if (!empty($testParams)) {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testParams));
                    }
                }
                
                $headers = json_decode($endpoint['headers'], true) ?? [];
                $curlHeaders = [];
                foreach ($headers as $header) {
                    $curlHeaders[] = $header['name'] . ': ' . $header['value'];
                }
                
                if (!empty($curlHeaders)) {
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
                }
                
                $startTime = microtime(true);
                $response = curl_exec($ch);
                $endTime = microtime(true);
                
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                
                curl_close($ch);
                
                $responseTime = round(($endTime - $startTime) * 1000);
                
                $result = [
                    'success' => $error ? false : true,
                    'http_code' => $httpCode,
                    'response_time_ms' => $responseTime,
                    'error' => $error ?: null,
                    'response' => json_decode($response, true) ?: $response,
                    'credits_cost' => $endpoint['credits_per_call']
                ];
                
                echo json_encode($result);
                exit;
                
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

$apiEndpoints = $pdo->query("SELECT * FROM api_endpoints ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$apiUsageStats = $pdo->query("
    SELECT 
        e.name as endpoint_name,
        COUNT(a.id) as call_count,
        SUM(a.credits_used) as total_credits_used,
        ROUND(AVG(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) * 100, 2) as success_rate
    FROM api_calls a
    JOIN api_endpoints e ON a.scraper_model = e.name
    GROUP BY e.name
    ORDER BY call_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Management Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
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
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .section {
            background: rgba(0, 0, 51, 0.7);
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            box-shadow: 0 0 15px rgba(0, 255, 255, 0.2);
            backdrop-filter: blur(10px);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .card {
            background: rgba(0, 0, 102, 0.5);
            padding: 1.5rem;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0, 255, 255, 0.2);
        }

        .btn {
            background: linear-gradient(to right, #0ff, #00ccff);
            color: #000033;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            box-shadow: 0 0 20px #0ff;
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }

        .btn-danger {
            background: linear-gradient(to right, #ff3366, #ff6666);
        }

        .btn-secondary {
            background: linear-gradient(to right, #9966ff, #6666ff);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }

        input, textarea, select {
            width: 100%;
            padding: 0.8rem;
            background: rgba(0, 0, 102, 0.5);
            border: 1px solid #0ff;
            border-radius: 5px;
            color: #0ff;
            font-family: 'Orbitron', sans-serif;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(0, 255, 255, 0.2);
        }

        th {
            background: rgba(0, 255, 255, 0.1);
        }

        .active-badge {
            background: #0ff;
            color: #000033;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .inactive-badge {
            background: #ff3366;
            color: #fff;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 51, 0.8);
            z-index: 1000;
        }

        .modal-content {
            background: rgba(0, 0, 51, 0.95);
            max-width: 800px;
            width: 90%;
            margin: 50px auto;
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 0 30px rgba(0, 255, 255, 0.3);
            max-height: 90vh;
            overflow-y: auto;
        }

        .close {
            float: right;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .close:hover {
            color: #ff3366;
        }

        .parameter-list {
            margin-top: 1rem;
        }

        .parameter-item {
            background: rgba(0, 0, 102, 0.5);
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .remove-btn {
            background: rgba(255, 51, 102, 0.2);
            color: #ff3366;
            border: 1px solid #ff3366;
            padding: 0.3rem 0.8rem;
            border-radius: 5px;
            cursor: pointer;
        }

        .add-btn {
            background: rgba(0, 255, 255, 0.2);
            color: #0ff;
            border: 1px solid #0ff;
            padding: 0.3rem 0.8rem;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 0.5rem;
        }

        .test-container {
            display: flex;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .test-panel {
            flex: 1;
            background: rgba(0, 0, 102, 0.5);
            padding: 1.5rem;
            border-radius: 10px;
        }

        .result-panel {
            flex: 1;
            background: rgba(0, 0, 102, 0.5);
            padding: 1.5rem;
            border-radius: 10px;
            max-height: 500px;
            overflow-y: auto;
        }

        .result-panel pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>API Management Dashboard</h1>
            <button class="btn" onclick="showCreateModal()">Create New API Endpoint</button>
        </div>

        <div class="section">
            <h2>API Endpoints</h2>
            <p style="margin-bottom: 15px; color: #0ff;">API endpoints can now be accessed using either <code>/api/endpoint</code> or <code>/api/EndpointName</code> (the name of the API). This makes the API more intuitive for users.</p>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Endpoint</th>
                        <th>Method</th>
                        <th>Credits</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($apiEndpoints as $endpoint): ?>
                    <tr>
                        <td><?= htmlspecialchars($endpoint['name']) ?></td>
                        <td><?= htmlspecialchars($endpoint['endpoint']) ?></td>
                        <td><?= htmlspecialchars($endpoint['method']) ?></td>
                        <td><?= htmlspecialchars($endpoint['credits_per_call']) ?></td>
                        <td>
                            <?php if ($endpoint['is_active']): ?>
                                <span class="active-badge">Active</span>
                            <?php else: ?>
                                <span class="inactive-badge">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-small" onclick="showEditModal(<?= $endpoint['id'] ?>)">Edit</button>
                            <button class="btn btn-small btn-secondary" onclick="showTestModal(<?= $endpoint['id'] ?>)">Test</button>
                            <button class="btn btn-small btn-danger" onclick="deleteEndpoint(<?= $endpoint['id'] ?>)">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <h2>API Usage Statistics</h2>
            <div class="grid">
                <?php foreach ($apiUsageStats as $stat): ?>
                <div class="card">
                    <h3><?= htmlspecialchars($stat['endpoint_name']) ?></h3>
                    <div class="stats">
                        <p>Total Calls: <?= $stat['call_count'] ?></p>
                        <p>Credits Used: <?= $stat['total_credits_used'] ?></p>
                        <p>Success Rate: <?= $stat['success_rate'] ?>%</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div id="endpointModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('endpointModal')">&times;</span>
            <h2 id="modalTitle">Create New API Endpoint</h2>
            <form id="endpointForm">
                <input type="hidden" id="endpointId" name="id">
                <input type="hidden" id="formAction" name="action" value="create">
                
                <div class="form-group">
                    <label for="name">API Name:</label>
                    <input type="text" id="name" name="name" required>
                </div>
                
                <div class="form-group">
                    <label for="endpoint">Endpoint Path:</label>
                    <input type="text" id="endpoint" name="endpoint" required placeholder="e.g., searchAmazon">
                </div>
                
                <div class="form-group">
                    <label for="description">Description:</label>
                    <textarea id="description" name="description"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="baseUrl">Base URL:</label>
                    <input type="text" id="baseUrl" name="base_url" required placeholder="e.g., https://api.example.com/endpoint">
                </div>
                
                <div class="form-group">
                    <label for="method">Method:</label>
                    <select id="method" name="method">
                        <option value="GET">GET</option>
                        <option value="POST">POST</option>
                        <option value="PUT">PUT</option>
                        <option value="DELETE">DELETE</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Headers:</label>
                    <div id="headersContainer" class="parameter-list"></div>
                    <button type="button" class="add-btn" onclick="addHeader()">Add Header</button>
                </div>
                
                <div class="form-group">
                    <label>Parameters:</label>
                    <div id="parametersContainer" class="parameter-list"></div>
                    <button type="button" class="add-btn" onclick="addParameter()">Add Parameter</button>
                </div>
                
                <div class="form-group">
                    <label>Required Parameters:</label>
                    <div id="requiredParamsContainer" class="parameter-list"></div>
                    <button type="button" class="add-btn" onclick="addRequiredParam()">Add Required Parameter</button>
                </div>
                
                <div class="form-group">
                    <label for="creditsPerCall">Credits Per Call:</label>
                    <input type="number" id="creditsPerCall" name="credits_per_call" min="1" value="1" required>
                </div>
                
                <div class="form-group" id="activeGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="isActive" name="is_active" checked>
                        Active
                    </label>
                </div>
                
                <button type="submit" class="btn">Save API Endpoint</button>
            </form>
        </div>
    </div>

    <div id="testModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="hideModal('testModal')">&times;</span>
            <h2>Test API Endpoint</h2>
            <div id="apiDetails"></div>
            
            <div class="test-container">
                <div class="test-panel">
                    <h3>Parameters</h3>
                    <form id="testForm">
                        <input type="hidden" id="testEndpointId" name="id">
                        <input type="hidden" name="action" value="test">
                        <div id="testParametersContainer"></div>
                        <button type="submit" class="btn">Run Test</button>
                    </form>
                </div>
                
                <div class="result-panel">
                    <h3>Results</h3>
                    <pre id="testResults">No results yet. Run a test to see the response.</pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        let apiEndpoints = <?= json_encode($apiEndpoints) ?>;
        
        function showCreateModal() {
            document.getElementById('modalTitle').textContent = 'Create New API Endpoint';
            document.getElementById('formAction').value = 'create';
            document.getElementById('endpointId').value = '';
            document.getElementById('endpointForm').reset();
            document.getElementById('activeGroup').style.display = 'none';
            
            document.getElementById('headersContainer').innerHTML = '';
            document.getElementById('parametersContainer').innerHTML = '';
            document.getElementById('requiredParamsContainer').innerHTML = '';
            
            document.getElementById('endpointModal').style.display = 'block';
        }
        
        function showEditModal(id) {
            const endpoint = apiEndpoints.find(ep => ep.id == id);
            if (!endpoint) return;
            
            document.getElementById('modalTitle').textContent = 'Edit API Endpoint';
            document.getElementById('formAction').value = 'update';
            document.getElementById('endpointId').value = endpoint.id;
            document.getElementById('name').value = endpoint.name;
            document.getElementById('endpoint').value = endpoint.endpoint;
            document.getElementById('description').value = endpoint.description;
            document.getElementById('baseUrl').value = endpoint.base_url;
            document.getElementById('method').value = endpoint.method;
            document.getElementById('creditsPerCall').value = endpoint.credits_per_call;
            document.getElementById('isActive').checked = endpoint.is_active == 1;
            document.getElementById('activeGroup').style.display = 'block';
            
            const headersContainer = document.getElementById('headersContainer');
            headersContainer.innerHTML = '';
            const headers = JSON.parse(endpoint.headers || '[]');
            
            headers.forEach((header, index) => {
                const headerHTML = `
                    <div class="parameter-item">
                        <div>
                            <input type="text" name="headers[${index}][name]" value="${header.name}" placeholder="Header Name" required>
                            <input type="text" name="headers[${index}][value]" value="${header.value}" placeholder="Value" required>
                        </div>
                        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                `;
                headersContainer.insertAdjacentHTML('beforeend', headerHTML);
            });
            
            const parametersContainer = document.getElementById('parametersContainer');
            parametersContainer.innerHTML = '';
            const parameters = JSON.parse(endpoint.parameters || '[]');
            
            parameters.forEach((param, index) => {
                const paramHTML = `
                    <div class="parameter-item">
                        <div>
                            <input type="text" name="parameters[${index}][name]" value="${param.name}" placeholder="Parameter Name" required>
                            <input type="text" name="parameters[${index}][type]" value="${param.type}" placeholder="Type" required>
                            <input type="text" name="parameters[${index}][description]" value="${param.description}" placeholder="Description">
                        </div>
                        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                `;
                parametersContainer.insertAdjacentHTML('beforeend', paramHTML);
            });
            
            const requiredParamsContainer = document.getElementById('requiredParamsContainer');
            requiredParamsContainer.innerHTML = '';
            const requiredParams = JSON.parse(endpoint.required_parameters || '[]');
            
            requiredParams.forEach((param, index) => {
                const paramHTML = `
                    <div class="parameter-item">
                        <div>
                            <input type="text" name="required_parameters[${index}]" value="${param}" placeholder="Parameter Name" required>
                        </div>
                        <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                    </div>
                `;
                requiredParamsContainer.insertAdjacentHTML('beforeend', paramHTML);
            });
            
            document.getElementById('endpointModal').style.display = 'block';
        }
        
        function showTestModal(id) {
            const endpoint = apiEndpoints.find(ep => ep.id == id);
            if (!endpoint) return;
            
            document.getElementById('testEndpointId').value = endpoint.id;
            
            const apiDetails = document.getElementById('apiDetails');
            apiDetails.innerHTML = `
                <h3>${endpoint.name}</h3>
                <p><strong>Endpoint:</strong> ${endpoint.endpoint}</p>
                <p><strong>Method:</strong> ${endpoint.method}</p>
                <p><strong>Base URL:</strong> ${endpoint.base_url}</p>
                <p><strong>Credits per call:</strong> ${endpoint.credits_per_call}</p>
            `;
            
            const testParametersContainer = document.getElementById('testParametersContainer');
            testParametersContainer.innerHTML = '';
            
            const parameters = JSON.parse(endpoint.parameters || '[]');
            const requiredParams = JSON.parse(endpoint.required_parameters || '[]');
            
            parameters.forEach(param => {
                const isRequired = requiredParams.includes(param.name);
                
                const paramHTML = `
                    <div class="form-group">
                        <label for="param_${param.name}">${param.name}${isRequired ? ' (Required)' : ''}:</label>
                        <input type="text" id="param_${param.name}" name="test_params[${param.name}]" placeholder="${param.description}" ${isRequired ? 'required' : ''}>
                    </div>
                `;
                testParametersContainer.insertAdjacentHTML('beforeend', paramHTML);
            });
            
            document.getElementById('testResults').textContent = 'No results yet. Run a test to see the response.';
            
            document.getElementById('testModal').style.display = 'block';
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function addHeader() {
            const headersContainer = document.getElementById('headersContainer');
            const index = headersContainer.children.length;
            
            const headerHTML = `
                <div class="parameter-item">
                    <div>
                        <input type="text" name="headers[${index}][name]" placeholder="Header Name" required>
                        <input type="text" name="headers[${index}][value]" placeholder="Value" required>
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                </div>
            `;
            
            headersContainer.insertAdjacentHTML('beforeend', headerHTML);
        }
        
        function addParameter() {
            const parametersContainer = document.getElementById('parametersContainer');
            const index = parametersContainer.children.length;
            
            const paramHTML = `
                <div class="parameter-item">
                    <div>
                        <input type="text" name="parameters[${index}][name]" placeholder="Parameter Name" required>
                        <input type="text" name="parameters[${index}][type]" placeholder="Type" required>
                        <input type="text" name="parameters[${index}][description]" placeholder="Description">
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                </div>
            `;
            
            parametersContainer.insertAdjacentHTML('beforeend', paramHTML);
        }
        
        function addRequiredParam() {
            const requiredParamsContainer = document.getElementById('requiredParamsContainer');
            const index = requiredParamsContainer.children.length;
            
            const paramHTML = `
                <div class="parameter-item">
                    <div>
                        <input type="text" name="required_parameters[${index}]" placeholder="Parameter Name" required>
                    </div>
                    <button type="button" class="remove-btn" onclick="this.parentElement.remove()">Remove</button>
                </div>
            `;
            
            requiredParamsContainer.insertAdjacentHTML('beforeend', paramHTML);
        }
        
        function deleteEndpoint(id) {
            if (!confirm('Are you sure you want to delete this API endpoint?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('api_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the endpoint');
            });
        }
        
        document.getElementById('endpointForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('api_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the endpoint');
            });
        });
        
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const resultsContainer = document.getElementById('testResults');
            
            resultsContainer.textContent = 'Running test...';
            
            fetch('api_dashboard.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                resultsContainer.textContent = JSON.stringify(data, null, 2);
            })
            .catch(error => {
                console.error('Error:', error);
                resultsContainer.textContent = 'Error: ' + error.message;
            });
        });
        
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        });
    </script>
</body>
</html>
