<?php
/*
 * Endpoint cập nhật mobile notification token
 * URL: /plugins/glpimobilenotification/ajax/update_token.php
 * 
 * Tương tự cách plugin install.php sử dụng global $DB
 */

// Tìm thư mục gốc GLPI
$plugin_dir = __DIR__;
$a = explode("/", $plugin_dir);
array_pop($a); // bỏ 'ajax'
array_pop($a); // bỏ 'glpimobilenotification'
array_pop($a); // bỏ 'plugins'
$glpi_root = implode("/", $a);

define('GLPI_ROOT', $glpi_root);

// Include GLPI core
include_once(GLPI_ROOT . '/inc/includes.php');

// Header JSON
header('Content-Type: application/json');

// Sử dụng global $DB như trong install.php
global $DB;

// Logger giống install.php
$logfile = dirname(__DIR__) . "/smartdesk_mobile.log";

function token_log($msg) {
    global $logfile;
    $line = "[TOKEN UPDATE] $msg\n";
    if (file_exists($logfile)) {
        @error_log($line, 3, $logfile);
    } else {
        @error_log($line);
    }
}

try {
    token_log("Endpoint called from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    
    // Kiểm tra method
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'POST' && $method !== 'PUT') {
        http_response_code(405);
        die(json_encode(['error' => 'Method not allowed']));
    }
    
    // Đọc input JSON
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        token_log("Invalid JSON: " . json_last_error_msg());
        http_response_code(400);
        die(json_encode(['error' => 'Invalid JSON']));
    }
    
    // Validate input
    if (!isset($input['user_id']) || !isset($input['mobile_notification'])) {
        token_log("Missing required fields");
        http_response_code(400);
        die(json_encode(['error' => 'Missing user_id or mobile_notification']));
    }
    
    $userId = (int)$input['user_id'];
    $token = trim($input['mobile_notification']);
    
    if ($userId <= 0) {
        token_log("Invalid user_id: $userId");
        http_response_code(400);
        die(json_encode(['error' => 'Invalid user_id']));
    }
    
    // Validate token format
    if (!empty($token) && !preg_match('/^(FBT:|FTIOS:)/', $token)) {
        token_log("Invalid token format for user #$userId");
        http_response_code(400);
        die(json_encode(['error' => 'Token must start with FBT: or FTIOS:']));
    }
    
    token_log("Updating token for user #$userId");
    
    // Kiểm tra user tồn tại (dùng cú pháp giống install.php)
    $checkQuery = "SELECT name FROM glpi_users WHERE id = $userId";
    $checkResult = $DB->query($checkQuery);
    
    if (!$checkResult || $DB->numrows($checkResult) == 0) {
        token_log("User #$userId not found");
        http_response_code(404);
        die(json_encode(['error' => 'User not found']));
    }
    
    $userData = $DB->fetchAssoc($checkResult);
    $userName = $userData['name'];
    
    token_log("Found user: $userName (#$userId)");
    
    // Update token (dùng $DB->escape giống install.php)
    $escapedToken = $DB->escape($token);
    $updateQuery = "UPDATE glpi_users SET mobile_notification = '$escapedToken' WHERE id = $userId";
    
    // Dùng queryOrDie hoặc query
    $updateResult = $DB->query($updateQuery);
    
    if (!$updateResult) {
        $error = $DB->error();
        token_log("Update failed for user #$userId: $error");
        http_response_code(500);
        die(json_encode(['error' => 'Update failed', 'message' => $error]));
    }
    
    // Verify update
    $verifyQuery = "SELECT mobile_notification FROM glpi_users WHERE id = $userId";
    $verifyResult = $DB->query($verifyQuery);
    
    if ($verifyResult) {
        $verifyData = $DB->fetchAssoc($verifyResult);
        $savedToken = $verifyData['mobile_notification'] ?? '';
        token_log("SUCCESS - User: $userName (#$userId) - Token preview: " . substr($savedToken, 0, 30) . "...");
    }
    
    // Success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'user_id' => $userId,
        'user_name' => $userName,
        'token_preview' => substr($token, 0, 20) . '...',
        'message' => 'Token updated successfully'
    ]);
    
} catch (Exception $e) {
    token_log("ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
