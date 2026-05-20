<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['POST']);

$data = apiRequestData();

$email = trim((string) ($data['email'] ?? ''));
$password = (string) ($data['password'] ?? '');

if ($email === '' || $password === '') {
    apiRespond(400, ['success' => false, 'error' => 'Email and password required']);
}

try {
    $stmt = $pdo->prepare("
        SELECT
            u.*,
            f.name AS faculty_name,
            d.name AS department_name
        FROM users u
        LEFT JOIN faculties f ON u.faculty_id = f.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.email = ? AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        apiRespond(401, ['success' => false, 'error' => 'Invalid credentials']);
    }

    logActivity($pdo, (int) $user['id'], 'mobile_login', 'User logged in through the mobile app');

    apiRespond(200, array_merge([
        'success' => true,
    ], apiUserPayload($user)));
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to process login right now']);
}
?>
