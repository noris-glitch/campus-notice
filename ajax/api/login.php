<?php
require_once '../config/database.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$data = json_decode(file_get_contents('php://input'), true);
$email = $data['email'] ?? '';
$password = $data['password'] ?? '';

if(empty($email) || empty($password)) {
    echo json_encode(['error' => 'Email and password required']);
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if($user && password_verify($password, $user['password'])) {
    echo json_encode([
        'success' => true,
        'user_id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'token' => base64_encode($user['id'] . ':' . $user['email'])
    ]);
} else {
    echo json_encode(['error' => 'Invalid credentials']);
}
?>