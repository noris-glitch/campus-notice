<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET']);

try {
    $user = apiFetchAuthenticatedUser($pdo, $_GET);
    $analyticsRange = trim((string) ($_GET['analytics_range'] ?? 'weekly'));

    $notificationStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $notificationStmt->execute([(int) $user['id']]);

    apiRespond(200, [
        'success' => true,
        'user' => apiUserPayload($user),
        'unread_notifications' => (int) $notificationStmt->fetchColumn(),
        'faculties' => apiFetchFaculties($pdo),
        'categories' => getAvailableNoticeCategories(),
        'dashboard' => ($user['role'] ?? '') === 'student'
            ? apiFetchStudentDashboard($pdo, $user)
            : apiFetchAdminDashboard($pdo, $user, $analyticsRange),
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Unable to load dashboard data right now']);
}
?>
