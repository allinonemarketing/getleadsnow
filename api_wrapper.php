<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
require_once 'includes/auth.php';

$requestPath = $_SERVER['REQUEST_URI'];
$pathParts = explode('/', trim(parse_url($requestPath, PHP_URL_PATH), '/'));
$endpoint = end($pathParts);

try {
    $stmt = $pdo->prepare("SELECT * FROM api_endpoints WHERE endpoint = ? AND is_active = 1");
    $stmt->execute([$endpoint]);
    $apiEndpoint = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$apiEndpoint) {
        $stmt = $pdo->prepare("SELECT * FROM api_endpoints WHERE name = ? AND is_active = 1");
        $stmt->execute([$endpoint]);
        $apiEndpoint = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if (!$apiEndpoint) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'API endpoint not found']);
        exit;
    }
    
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'No API key provided']);
        exit;
    }

    $userApiKey = $matches[1];

    $stmt = $pdo->prepare("SELECT user_id FROM api_keys WHERE api_key = ? AND is_active = 1");
    $stmt->execute([$userApiKey]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid API key']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $remainingCredits = $stmt->fetchColumn();

    $creditsRequired = $apiEndpoint['credits_per_call'];
    if ($remainingCredits < $creditsRequired) {
        http_response_code(402);
        echo json_encode([
            'success' => false, 
            'error' => 'Insufficient credits',
            'credits_required' => $creditsRequired,
            'credits_available' => $remainingCredits
        ]);
        exit;
    }
    
    $requiredParams = json_decode($apiEndpoint['required_parameters'], true) ?? [];
    $requestParams = $_REQUEST;
    
    foreach ($requiredParams as $param) {
        if (!isset($requestParams[$param]) || empty($requestParams[$param])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Missing required parameter: {$param}"]);
            exit;
        }
    }
    
    $startTime = microtime(true);
    
    $url = $apiEndpoint['base_url'];
    if ($apiEndpoint['method'] === 'GET' && !empty($requestParams)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($requestParams);
    }
    
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12000);
    
    if ($apiEndpoint['method'] !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $apiEndpoint['method']);
        
        if (!empty($requestParams)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestParams));
        }
    }
    
    $headers = json_decode($apiEndpoint['headers'], true) ?? [];
    $curlHeaders = [];
    foreach ($headers as $header) {
        $curlHeaders[] = $header['name'] . ': ' . $header['value'];
    }
    
    if (!empty($curlHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    $endTime = microtime(true);
    $responseTime = round(($endTime - $startTime) * 1000);
    
    header('Content-Type: application/json');
    
    if ($error) {
        $errorMessage = 'cURL error: ' . $error;
        
        logApiCall($pdo, $userId, $apiEndpoint, $requestParams, 'error', $errorMessage, 0);
        
        echo json_encode([
            'success' => false,
            'error' => $errorMessage,
            'response_time_ms' => $responseTime,
            'remaining_credits' => $remainingCredits
        ]);
        exit;
    }
    
    $decodedResponse = json_decode($response, true);
    $isValidJson = json_last_error() === JSON_ERROR_NONE;
    
    if (!$isValidJson) {
        logApiCall($pdo, $userId, $apiEndpoint, $requestParams, 'error', 'Invalid JSON response from API', 0);
        
        echo json_encode([
            'success' => false,
            'error' => 'Invalid JSON response from API',
            'raw_response' => $response,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTime,
            'remaining_credits' => $remainingCredits
        ]);
        exit;
    }
    
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMessage = 'API returned error status: ' . $httpCode;
        
        logApiCall($pdo, $userId, $apiEndpoint, $requestParams, 'error', $errorMessage, 0);
        
        echo json_encode([
            'success' => false,
            'error' => $errorMessage,
            'api_response' => $decodedResponse,
            'http_code' => $httpCode,
            'response_time_ms' => $responseTime,
            'remaining_credits' => $remainingCredits
        ]);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET credits = credits - ? 
            WHERE id = ? AND credits >= ?
        ");
        $success = $stmt->execute([$creditsRequired, $userId, $creditsRequired]);
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Insufficient credits');
        }

        $stmt = $pdo->prepare("
            UPDATE api_keys 
            SET credits_used = credits_used + ?,
                last_used_at = NOW() 
            WHERE api_key = ?
        ");
        $stmt->execute([$creditsRequired, $userApiKey]);
        
        logApiCall($pdo, $userId, $apiEndpoint, $requestParams, 'completed', null, $creditsRequired);
        
        $pdo->commit();
        
        $stmt = $pdo->prepare("SELECT credits FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $remainingCredits = $stmt->fetchColumn();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Error processing API call: ' . $e->getMessage());
    }
    
    $finalResponse = $decodedResponse;
    
    if (is_array($finalResponse)) {
        $finalResponse['response_time_ms'] = $responseTime;
        $finalResponse['remaining_credits'] = $remainingCredits;
        $finalResponse['credits_used'] = $creditsRequired;
        
        if (!isset($finalResponse['success'])) {
            $finalResponse['success'] = true;
        }
    }
    
    echo json_encode($finalResponse);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
    error_log('API Wrapper Error: ' . $e->getMessage());
}

function logApiCall($pdo, $userId, $apiEndpoint, $requestParams, $status, $errorMessage = null, $creditsUsed = 0) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO api_calls 
            (user_id, credits_used, scraper_model, url, search_query, input_params, status, error_message) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $searchQuery = $requestParams['query'] ?? $requestParams['search'] ?? '';
        
        $stmt->execute([
            $userId,
            $creditsUsed,
            $apiEndpoint['name'],
            $apiEndpoint['base_url'],
            $searchQuery,
            json_encode($requestParams),
            $status,
            $errorMessage
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log('Error logging API call: ' . $e->getMessage());
        return false;
    }
}
