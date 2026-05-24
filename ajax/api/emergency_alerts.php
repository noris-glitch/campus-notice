<?php
require_once __DIR__ . '/_mobile.php';

apiHandlePreflight();
apiRequireMethod(['GET', 'POST']);

function apiRequireSuperAdmin(array $user): void
{
    if (($user['role'] ?? '') !== 'super_admin') {
        apiRespond(403, ['success' => false, 'error' => 'Super administrator access is required']);
    }
}

function apiEmergencySeverities(): array
{
    return [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ];
}

function apiEmergencyAudienceConditions(PDO $pdo, array $user): array
{
    $conditions = [];
    $params = [];

    if (($user['role'] ?? '') === 'super_admin') {
        return [$conditions, $params];
    }

    if (featureColumnExists($pdo, 'emergency_alerts', 'is_active')) {
        $conditions[] = 'ea.is_active = 1';
    }

    $conditions[] = '(ea.expires_at IS NULL OR ea.expires_at > NOW())';

    if (featureColumnExists($pdo, 'emergency_alerts', 'target_faculty')) {
        $conditions[] = '(ea.target_faculty IS NULL OR ea.target_faculty = 0 OR ea.target_faculty = ?)';
        $params[] = apiNullableInt($user['faculty_id'] ?? null);
    }

    if (featureColumnExists($pdo, 'emergency_alerts', 'target_year')) {
        $userYear = apiNullableInt($user['year'] ?? null);
        if ($userYear === null) {
            $conditions[] = '(ea.target_year IS NULL OR ea.target_year = 0)';
        } else {
            $conditions[] = '(ea.target_year IS NULL OR ea.target_year = 0 OR ea.target_year = ?)';
            $params[] = $userYear;
        }
    }

    return [$conditions, $params];
}

function apiFetchEmergencyAlertsList(PDO $pdo, array $user): array
{
    if (!featureTableExists($pdo, 'emergency_alerts')) {
        return [];
    }

    [$conditions, $params] = apiEmergencyAudienceConditions($pdo, $user);
    $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

    $stmt = $pdo->prepare(
        "
        SELECT
            ea.*,
            u.name AS author_name,
            (SELECT COUNT(*) FROM emergency_alert_receipts WHERE alert_id = ea.id) AS total_recipients,
            (SELECT COUNT(*) FROM emergency_alert_receipts WHERE alert_id = ea.id AND is_read = 1) AS read_count
        FROM emergency_alerts ea
        LEFT JOIN users u ON ea.created_by = u.id
        $where
        ORDER BY ea.created_at DESC
        LIMIT 40
    "
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function apiCountActiveEmergencyAlerts(PDO $pdo, array $user): int
{
    if (!featureTableExists($pdo, 'emergency_alerts')) {
        return 0;
    }

    [$conditions, $params] = apiEmergencyAudienceConditions($pdo, $user);
    if (($user['role'] ?? '') === 'super_admin') {
        $conditions[] = featureColumnExists($pdo, 'emergency_alerts', 'is_active')
            ? 'ea.is_active = 1'
            : '1 = 1';
        $conditions[] = '(ea.expires_at IS NULL OR ea.expires_at > NOW())';
    }

    $where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emergency_alerts ea $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = apiFetchAuthenticatedUser($pdo, $_GET);
        $canCreate = (($user['role'] ?? '') === 'super_admin');

        apiRespond(200, [
            'success' => true,
            'active_count' => apiCountActiveEmergencyAlerts($pdo, $user),
            'alerts' => apiFetchEmergencyAlertsList($pdo, $user),
            'faculties' => $canCreate ? apiFetchFaculties($pdo) : [],
            'severities' => apiEmergencySeverities(),
            'years' => $canCreate ? apiAdminYears() : [],
            'can_create' => $canCreate,
        ]);
    }

    $data = apiRequestData();
    $user = apiFetchAuthenticatedUser($pdo, $data);
    apiRequireSuperAdmin($user);

    $action = trim((string) ($data['action'] ?? ''));
    if ($action !== 'create') {
        apiRespond(400, ['success' => false, 'error' => 'Unsupported emergency alert action']);
    }

    if (!featureTableExists($pdo, 'emergency_alerts') || !featureTableExists($pdo, 'emergency_alert_receipts')) {
        apiRespond(400, ['success' => false, 'error' => 'Emergency alert tables are not available on this server']);
    }

    $title = trim((string) ($data['title'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));
    $severity = trim((string) ($data['severity'] ?? 'medium'));
    $targetFaculty = apiNullableInt($data['target_faculty'] ?? null);
    $targetYear = apiNullableInt($data['target_year'] ?? null);
    $expiresAt = apiDateTimeOrNull($data['expires_at'] ?? null) ?: date('Y-m-d H:i:s', strtotime('+24 hours'));

    if ($title === '' || $message === '') {
        apiRespond(400, ['success' => false, 'error' => 'Title and message are required']);
    }

    if (!array_key_exists($severity, apiEmergencySeverities())) {
        apiRespond(400, ['success' => false, 'error' => 'Choose a valid emergency severity']);
    }

    $insert = $pdo->prepare(
        '
        INSERT INTO emergency_alerts (title, message, severity, target_faculty, target_year, expires_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    '
    );
    $insert->execute([
        $title,
        $message,
        $severity,
        $targetFaculty,
        $targetYear,
        $expiresAt,
        (int) $user['id'],
    ]);

    $alertId = (int) $pdo->lastInsertId();
    $userQuery = "SELECT id FROM users WHERE role = 'student' AND is_active = 1";
    $params = [];

    if ($targetFaculty) {
        $userQuery .= ' AND faculty_id = ?';
        $params[] = $targetFaculty;
    }

    if ($targetYear) {
        $userQuery .= ' AND year = ?';
        $params[] = $targetYear;
    }

    $targetStmt = $pdo->prepare($userQuery);
    $targetStmt->execute($params);
    $targetUsers = $targetStmt->fetchAll();

    $receiptStmt = $pdo->prepare('INSERT INTO emergency_alert_receipts (alert_id, user_id) VALUES (?, ?)');
    foreach ($targetUsers as $targetUser) {
        $receiptStmt->execute([$alertId, (int) $targetUser['id']]);
    }

    logActivity($pdo, (int) $user['id'], 'mobile_emergency_alert_created', 'Emergency alert ID ' . $alertId);

    apiRespond(200, [
        'success' => true,
        'message' => 'Emergency alert sent to ' . count($targetUsers) . ' students.',
    ]);
} catch (PDOException $e) {
    apiRespond(500, ['success' => false, 'error' => 'Emergency alert action failed right now']);
}
?>
