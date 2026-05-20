<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $prefs = getUserNotificationPreferences($pdo, (int) $user['id']);

        $bookmarkStmt = $pdo->prepare('SELECT COUNT(*) FROM bookmarks WHERE user_id = ?');
        $bookmarkStmt->execute([(int) $user['id']]);

        $viewStmt = $pdo->prepare('SELECT COUNT(*) FROM notice_views WHERE user_id = ?');
        $viewStmt->execute([(int) $user['id']]);

        $noticeCount = 0;
        if (($user['role'] ?? '') !== 'student') {
            if (($user['role'] ?? '') === 'super_admin') {
                $noticeCount = (int) $pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn();
            } else {
                $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notices WHERE posted_by = ?');
                $countStmt->execute([(int) $user['id']]);
                $noticeCount = (int) $countStmt->fetchColumn();
            }
        }

        apiRespond(200, [
            'success' => true,
            'user' => apiUserPayload($user),
            'faculties' => apiFetchFaculties($pdo),
            'categories' => getAvailableNoticeCategories(),
            'notification_preferences' => [
                'in_app_enabled' => apiBool($prefs['in_app_enabled'] ?? 1),
                'email_enabled' => apiBool($prefs['email_enabled'] ?? 0),
                'emergency_override' => apiBool($prefs['emergency_override'] ?? 1),
                'quiet_hours_start' => $prefs['quiet_hours_start'] ?? null,
                'quiet_hours_end' => $prefs['quiet_hours_end'] ?? null,
                'categories' => apiCsvValues($prefs['categories_csv'] ?? null),
            ],
            'stats' => [
                'bookmark_count' => (int) $bookmarkStmt->fetchColumn(),
                'viewed_count' => (int) $viewStmt->fetchColumn(),
                'notice_count' => $noticeCount,
            ],
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    $action = trim((string) ($data['action'] ?? ''));

    if ($action === 'update_profile') {
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $year = apiNullableInt($data['year'] ?? null);
        $facultyId = apiNullableInt($data['faculty_id'] ?? null);
        $membership = apiNullableString($data['membership'] ?? null);

        if ($name === '' || $email === '') {
            apiRespond(400, ['success' => false, 'error' => 'Name and email are required']);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiRespond(400, ['success' => false, 'error' => 'Please enter a valid email address']);
        }

        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id != ?');
        $checkStmt->execute([$email, (int) $user['id']]);
        if ((int) $checkStmt->fetchColumn() > 0) {
            apiRespond(400, ['success' => false, 'error' => 'Email already used by another account']);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET name = ?, email = ?, faculty_id = ?, year = ?, membership = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $facultyId, $year, $membership, (int) $user['id']]);

        $freshUser = apiFetchAuthenticatedUser($pdo, [
            'user_id' => $user['id'],
            'token' => apiBuildToken(array_merge($user, ['email' => $email])),
        ]);

        apiRespond(200, [
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => apiUserPayload($freshUser),
        ]);
    }

    if ($action === 'save_preferences') {
        $categories = isset($data['categories']) && is_array($data['categories'])
            ? $data['categories']
            : getAvailableNoticeCategories();

        saveUserNotificationPreferences($pdo, (int) $user['id'], [
            'in_app_enabled' => !empty($data['in_app_enabled']),
            'email_enabled' => !empty($data['email_enabled']),
            'emergency_override' => !empty($data['emergency_override']),
            'quiet_hours_start' => $data['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $data['quiet_hours_end'] ?? null,
            'categories' => $categories,
        ]);

        apiRespond(200, [
            'success' => true,
            'message' => 'Notification preferences saved',
        ]);
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($data['current_password'] ?? '');
        $newPassword = (string) ($data['new_password'] ?? '');
        $confirmPassword = (string) ($data['confirm_password'] ?? '');

        if (!password_verify($currentPassword, (string) $user['password'])) {
            apiRespond(400, ['success' => false, 'error' => 'Current password is incorrect']);
        }

        if (strlen($newPassword) < 6) {
            apiRespond(400, ['success' => false, 'error' => 'New password must be at least 6 characters']);
        }

        if ($newPassword !== $confirmPassword) {
            apiRespond(400, ['success' => false, 'error' => 'New passwords do not match']);
        }

        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int) $user['id']]);

        apiRespond(200, [
            'success' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    apiRespond(400, ['success' => false, 'error' => 'Unsupported profile action']);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to update your profile right now']);
}
?>
