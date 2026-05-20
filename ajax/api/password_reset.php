<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['POST']);

function apiResetPasswordErrors(string $password, string $confirmPassword): array
{
    $errors = [];

    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }

    return $errors;
}

try {
    $data = apiRequestData();
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'request_reset') {
        $email = trim((string) ($data['email'] ?? ''));

        if ($email === '') {
            apiRespond(400, ['success' => false, 'error' => 'Please enter your email address']);
        }

        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            apiRespond(404, ['success' => false, 'error' => 'No account found with that email address']);
        }

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $stmt = $pdo->prepare('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?');
        $stmt->execute([$token, $expires, (int) $user['id']]);

        apiRespond(200, [
            'success' => true,
            'message' => 'Reset request created successfully. Set your new password now.',
            'reset_token' => $token,
            'expires_at' => $expires,
        ]);
    }

    if ($action === 'reset_password') {
        $token = trim((string) ($data['token'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');

        if ($token === '') {
            apiRespond(400, ['success' => false, 'error' => 'Reset token is required']);
        }

        $errors = apiResetPasswordErrors($password, $confirmPassword);
        if (!empty($errors)) {
            apiRespond(400, ['success' => false, 'error' => $errors[0], 'errors' => $errors]);
        }

        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            apiRespond(400, ['success' => false, 'error' => 'Invalid or expired reset request']);
        }

        $stmt = $pdo->prepare('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?');
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);

        logActivity($pdo, (int) $user['id'], 'mobile_password_reset', 'Password reset through the mobile app');

        apiRespond(200, [
            'success' => true,
            'message' => 'Password reset successfully. You can now log in.',
        ]);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported password reset action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to reset the password right now']);
}
?>
