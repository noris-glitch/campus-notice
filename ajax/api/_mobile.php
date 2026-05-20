<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

function apiRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function apiHandlePreflight(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

function apiRequireMethod(array $methods): void
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($requestMethod, $methods, true)) {
        apiRespond(405, ['success' => false, 'error' => 'Method not allowed']);
    }
}

function apiRequestData(): array
{
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    return $_POST;
}

function apiBuildToken(array $user): string
{
    return base64_encode((string) $user['id'] . ':' . (string) $user['email']);
}

function apiFetchAuthenticatedUser(PDO $pdo, array $source): array
{
    $userId = isset($source['user_id']) ? (int) $source['user_id'] : 0;
    $token = trim((string) ($source['token'] ?? ''));

    if ($userId <= 0 || $token === '') {
        apiRespond(400, ['success' => false, 'error' => 'Missing authentication details']);
    }

    $stmt = $pdo->prepare("
        SELECT
            u.*,
            f.name AS faculty_name
        FROM users u
        LEFT JOIN faculties f ON u.faculty_id = f.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || (int) ($user['is_active'] ?? 0) !== 1) {
        apiRespond(401, ['success' => false, 'error' => 'Invalid user']);
    }

    $decodedToken = base64_decode($token, true);
    $expectedToken = (string) $user['id'] . ':' . (string) $user['email'];

    if ($decodedToken === false || $decodedToken !== $expectedToken) {
        apiRespond(401, ['success' => false, 'error' => 'Invalid token']);
    }

    return $user;
}

function apiUserPayload(array $user): array
{
    return [
        'user_id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'role_label' => getRoleDisplayName((string) $user['role'], $user['admin_type'] ?? null),
        'admin_type' => $user['admin_type'] ?? null,
        'faculty_id' => isset($user['faculty_id']) ? (int) $user['faculty_id'] : null,
        'faculty_name' => $user['faculty_name'] ?? null,
        'year' => isset($user['year']) ? (int) $user['year'] : null,
        'student_id' => $user['student_id'] ?? null,
        'membership' => $user['membership'] ?? null,
        'profile_picture' => $user['profile_picture'] ?? null,
        'token' => apiBuildToken($user),
    ];
}

function apiBool($value): int
{
    return !empty($value) ? 1 : 0;
}

function apiNullableInt($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return (int) $value;
}

function apiNullableString($value): ?string
{
    $text = trim((string) $value);
    return $text === '' ? null : $text;
}

function apiDateTimeOrNull($value, bool $endOfDay = false): ?string
{
    $text = trim((string) $value);
    if ($text === '') {
        return null;
    }

    $timestamp = strtotime($text);
    if ($timestamp === false) {
        return null;
    }

    if ($endOfDay && strlen($text) <= 10) {
        return date('Y-m-d 23:59:59', $timestamp);
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function apiCsvValues(?string $value): array
{
    if (function_exists('csvValueToArray')) {
        return csvValueToArray($value);
    }

    if ($value === null || trim($value) === '') {
        return [];
    }

    $parts = array_map('trim', explode(',', $value));
    return array_values(array_filter($parts));
}

function apiTimeAgo(?string $timestamp): ?string
{
    if (!$timestamp) {
        return null;
    }

    $timeAgo = strtotime($timestamp);
    if ($timeAgo === false) {
        return null;
    }

    $difference = time() - $timeAgo;
    $minutes = round($difference / 60);
    $hours = round($difference / 3600);
    $days = round($difference / 86400);
    $weeks = round($difference / 604800);
    $months = round($difference / 2629440);
    $years = round($difference / 31553280);

    if ($difference <= 60) {
        return 'Just now';
    }
    if ($minutes <= 60) {
        return $minutes === 1 ? '1 minute ago' : $minutes . ' minutes ago';
    }
    if ($hours <= 24) {
        return $hours === 1 ? '1 hour ago' : $hours . ' hours ago';
    }
    if ($days <= 7) {
        return $days === 1 ? 'yesterday' : $days . ' days ago';
    }
    if ($weeks <= 4.3) {
        return $weeks === 1 ? '1 week ago' : $weeks . ' weeks ago';
    }
    if ($months <= 12) {
        return $months === 1 ? '1 month ago' : $months . ' months ago';
    }

    return $years === 1 ? '1 year ago' : $years . ' years ago';
}

function apiFetchFaculties(PDO $pdo): array
{
    try {
        if (!featureTableExists($pdo, 'faculties')) {
            return [];
        }

        $stmt = $pdo->query('SELECT id, name, dean_name FROM faculties ORDER BY name');
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function apiNoticeBaseConditions(PDO $pdo, array $user, bool $includeArchived = false, string $alias = 'n'): array
{
    $targetColumn = getNoticeTargetColumn($pdo) ?: 'faculty_target';
    $conditions = [];
    $params = [];

    if ($includeArchived) {
        $conditions[] = "$alias.status IN ('published', 'archived')";
    } else {
        $conditions[] = "$alias.status = 'published'";
        $conditions[] = "($alias.expire_at IS NULL OR $alias.expire_at > NOW())";
    }

    $conditions[] = "($alias.publish_at IS NULL OR $alias.publish_at <= NOW())";

    if (($user['role'] ?? '') === 'student') {
        $conditions[] = "($alias.$targetColumn IS NULL OR $alias.$targetColumn = 0 OR $alias.$targetColumn = ?)";
        $conditions[] = "($alias.year_target IS NULL OR $alias.year_target = 0 OR $alias.year_target = ?)";
        $params[] = apiNullableInt($user['faculty_id']);
        $params[] = apiNullableInt($user['year']);
    }

    return [$conditions, $params];
}

function apiFetchVisibleNotices(PDO $pdo, array $user, array $filters = []): array
{
    [$conditions, $params] = apiNoticeBaseConditions(
        $pdo,
        $user,
        !empty($filters['include_archived'])
    );

    $userId = (int) $user['id'];
    $limit = isset($filters['limit']) ? max(1, min((int) $filters['limit'], 100)) : 100;

    if (!empty($filters['notice_id'])) {
        $conditions[] = 'n.id = ?';
        $params[] = (int) $filters['notice_id'];
    }

    $keyword = trim((string) ($filters['q'] ?? ''));
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';
        $conditions[] = '(n.title LIKE ? OR n.content LIKE ? OR u.name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '') {
        $conditions[] = 'n.category = ?';
        $params[] = $category;
    }

    $priority = trim((string) ($filters['priority'] ?? ''));
    if ($priority !== '') {
        $conditions[] = 'n.priority = ?';
        $params[] = $priority;
    }

    $statusFilter = trim((string) ($filters['status'] ?? ''));
    if ($statusFilter === 'current') {
        $conditions[] = '(n.expire_at IS NULL OR n.expire_at > NOW())';
    } elseif ($statusFilter === 'expired') {
        $conditions[] = 'n.expire_at IS NOT NULL AND n.expire_at <= NOW()';
    }

    $dateFrom = trim((string) ($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $conditions[] = 'DATE(COALESCE(n.publish_at, n.created_at)) >= ?';
        $params[] = $dateFrom;
    }

    $dateTo = trim((string) ($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $conditions[] = 'DATE(COALESCE(n.publish_at, n.created_at)) <= ?';
        $params[] = $dateTo;
    }

    if (!empty($filters['bookmark_only'])) {
        $conditions[] = 'b.id IS NOT NULL';
    }

    $sql = "
        SELECT
            n.*,
            u.name AS author_name,
            CASE WHEN b.id IS NULL THEN 0 ELSE 1 END AS is_bookmarked,
            CASE WHEN nv.id IS NULL THEN 0 ELSE 1 END AS has_viewed,
            COALESCE(na.status, '') AS acknowledgement_status
        FROM notices n
        JOIN users u ON n.posted_by = u.id
        LEFT JOIN bookmarks b ON b.notice_id = n.id AND b.user_id = ?
        LEFT JOIN notice_views nv ON nv.notice_id = n.id AND nv.user_id = ?
        LEFT JOIN notice_acknowledgements na ON na.notice_id = n.id AND na.user_id = ?
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY n.is_pinned DESC, COALESCE(n.publish_at, n.created_at) DESC
        LIMIT $limit
    ";

    array_unshift($params, $userId, $userId, $userId);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notices = $stmt->fetchAll();

    foreach ($notices as &$notice) {
        $notice['is_bookmarked'] = (int) $notice['is_bookmarked'];
        $notice['has_viewed'] = (int) $notice['has_viewed'];
        $notice['requires_acknowledgement'] = apiBool($notice['requires_acknowledgement'] ?? 0);
        $notice['is_pinned'] = apiBool($notice['is_pinned'] ?? 0);
    }

    return $notices;
}

function apiCanManageNotice(array $user, array $notice): bool
{
    if (($user['role'] ?? '') === 'super_admin') {
        return true;
    }

    return ($user['role'] ?? '') === 'admin' && (int) $notice['posted_by'] === (int) $user['id'];
}

function apiFetchNoticeById(PDO $pdo, int $noticeId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            n.*,
            u.name AS author_name
        FROM notices n
        JOIN users u ON n.posted_by = u.id
        WHERE n.id = ?
        LIMIT 1
    ");
    $stmt->execute([$noticeId]);
    $notice = $stmt->fetch();

    return $notice ?: null;
}

function apiEnsureNoticeVisible(PDO $pdo, array $user, int $noticeId): array
{
    $notice = apiFetchNoticeById($pdo, $noticeId);
    if (!$notice) {
        apiRespond(404, ['success' => false, 'error' => 'Notice not found']);
    }

    if (apiCanManageNotice($user, $notice)) {
        return $notice;
    }

    $visible = apiFetchVisibleNotices($pdo, $user, ['notice_id' => $noticeId, 'limit' => 1, 'include_archived' => true]);
    if (empty($visible)) {
        apiRespond(403, ['success' => false, 'error' => 'You do not have access to this notice']);
    }

    return $notice;
}

function apiMarkNoticeViewed(PDO $pdo, int $noticeId, int $userId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO notice_views (notice_id, user_id) VALUES (?, ?)');
    $stmt->execute([$noticeId, $userId]);
}

function apiToggleBookmark(PDO $pdo, int $noticeId, int $userId): string
{
    $stmt = $pdo->prepare('SELECT id FROM bookmarks WHERE user_id = ? AND notice_id = ? LIMIT 1');
    $stmt->execute([$userId, $noticeId]);
    $bookmarkId = $stmt->fetchColumn();

    if ($bookmarkId) {
        $delete = $pdo->prepare('DELETE FROM bookmarks WHERE id = ?');
        $delete->execute([$bookmarkId]);
        return 'removed';
    }

    $insert = $pdo->prepare('INSERT INTO bookmarks (user_id, notice_id) VALUES (?, ?)');
    $insert->execute([$userId, $noticeId]);
    return 'added';
}

function apiAcknowledgeNotice(PDO $pdo, int $noticeId, int $userId): void
{
    $stmt = $pdo->prepare("
        INSERT INTO notice_acknowledgements (notice_id, user_id, status, acknowledged_at)
        VALUES (?, ?, 'acknowledged', NOW())
        ON DUPLICATE KEY UPDATE
            status = 'acknowledged',
            acknowledged_at = NOW()
    ");
    $stmt->execute([$noticeId, $userId]);
}

function apiFetchStudentDashboard(PDO $pdo, array $user): array
{
    [$conditions, $params] = apiNoticeBaseConditions($pdo, $user, false);
    $urgentConditions = $conditions;
    $urgentConditions[] = "(n.priority IN ('high', 'critical') OR n.requires_acknowledgement = 1)";

    $baseSql = 'FROM notices n WHERE ' . implode(' AND ', $conditions);
    $urgentSql = 'FROM notices n WHERE ' . implode(' AND ', $urgentConditions);

    $bookmarkStmt = $pdo->prepare('SELECT COUNT(*) FROM bookmarks WHERE user_id = ?');
    $bookmarkStmt->execute([(int) $user['id']]);

    $viewStmt = $pdo->prepare('SELECT COUNT(*) FROM notice_views WHERE user_id = ?');
    $viewStmt->execute([(int) $user['id']]);

    $unreadSql = "
        SELECT COUNT(*)
        $baseSql
        AND NOT EXISTS (
            SELECT 1
            FROM notice_views nv
            WHERE nv.notice_id = n.id AND nv.user_id = ?
        )
    ";
    $unreadStmt = $pdo->prepare($unreadSql);
    $unreadStmt->execute(array_merge($params, [(int) $user['id']]));

    $urgentStmt = $pdo->prepare("SELECT COUNT(*) $urgentSql");
    $urgentStmt->execute($params);

    return [
        'bookmark_count' => (int) $bookmarkStmt->fetchColumn(),
        'viewed_count' => (int) $viewStmt->fetchColumn(),
        'unread_count' => (int) $unreadStmt->fetchColumn(),
        'urgent_count' => (int) $urgentStmt->fetchColumn(),
        'recent_notices' => apiFetchVisibleNotices($pdo, $user, ['limit' => 5]),
    ];
}

function apiFetchAdminDashboard(PDO $pdo, array $user): array
{
    $userRole = (string) ($user['role'] ?? 'admin');
    $userId = (int) $user['id'];
    $facultyId = apiNullableInt($user['faculty_id']);

    if ($userRole === 'super_admin') {
        $summary = [
            'total_notices' => (int) $pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn(),
            'total_students' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
            'total_views' => (int) $pdo->query('SELECT COUNT(*) FROM notice_views')->fetchColumn(),
            'total_bookmarks' => (int) $pdo->query('SELECT COUNT(*) FROM bookmarks')->fetchColumn(),
            'pending_approvals' => (int) $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'pending_review'")->fetchColumn(),
            'open_questions' => (int) $pdo->query("SELECT COUNT(*) FROM notice_questions WHERE status = 'open'")->fetchColumn(),
        ];

        $recentNoticeStmt = $pdo->query("
            SELECT
                n.*,
                u.name AS author_name
            FROM notices n
            JOIN users u ON n.posted_by = u.id
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $recentStudentStmt = $pdo->query("
            SELECT id, name, email, year, created_at
            FROM users
            WHERE role = 'student'
            ORDER BY created_at DESC
            LIMIT 5
        ");
    } else {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM notices WHERE posted_by = ?');
        $countStmt->execute([$userId]);
        $summary['total_notices'] = (int) $countStmt->fetchColumn();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'student' AND faculty_id = ?");
        $countStmt->execute([$facultyId]);
        $summary['total_students'] = (int) $countStmt->fetchColumn();

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notice_views nv
            JOIN notices n ON nv.notice_id = n.id
            WHERE n.posted_by = ?
        ");
        $countStmt->execute([$userId]);
        $summary['total_views'] = (int) $countStmt->fetchColumn();

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookmarks b
            JOIN notices n ON b.notice_id = n.id
            WHERE n.posted_by = ?
        ");
        $countStmt->execute([$userId]);
        $summary['total_bookmarks'] = (int) $countStmt->fetchColumn();

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notices
            WHERE posted_by = ? AND status = 'pending_review'
        ");
        $countStmt->execute([$userId]);
        $summary['pending_approvals'] = (int) $countStmt->fetchColumn();

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM notice_questions nq
            JOIN notices n ON nq.notice_id = n.id
            WHERE n.posted_by = ? AND nq.status = 'open'
        ");
        $countStmt->execute([$userId]);
        $summary['open_questions'] = (int) $countStmt->fetchColumn();

        $recentNoticeStmt = $pdo->prepare("
            SELECT
                n.*,
                u.name AS author_name
            FROM notices n
            JOIN users u ON n.posted_by = u.id
            WHERE n.posted_by = ?
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $recentNoticeStmt->execute([$userId]);

        $recentStudentStmt = $pdo->prepare("
            SELECT id, name, email, year, created_at
            FROM users
            WHERE role = 'student' AND faculty_id = ?
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $recentStudentStmt->execute([$facultyId]);
    }

    return array_merge($summary, [
        'recent_notices' => $recentNoticeStmt->fetchAll(),
        'recent_students' => $recentStudentStmt->fetchAll(),
    ]);
}

function apiFetchAdminNotices(PDO $pdo, array $user): array
{
    $query = "
        SELECT
            n.*,
            u.name AS author_name,
            (SELECT COUNT(*) FROM notice_views nv WHERE nv.notice_id = n.id) AS view_count,
            (SELECT COUNT(*) FROM bookmarks b WHERE b.notice_id = n.id) AS bookmark_count,
            (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id) AS ack_total,
            (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id AND na.status = 'acknowledged') AS ack_done,
            (SELECT COUNT(*) FROM notice_questions nq WHERE nq.notice_id = n.id AND nq.status = 'open') AS open_questions,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'sent') AS email_sent,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'failed') AS email_failed
        FROM notices n
        JOIN users u ON n.posted_by = u.id
    ";

    if (($user['role'] ?? '') === 'super_admin') {
        $stmt = $pdo->query($query . " ORDER BY FIELD(n.status, 'pending_review', 'scheduled', 'published', 'draft', 'rejected', 'archived'), n.created_at DESC");
    } else {
        $stmt = $pdo->prepare($query . " WHERE n.posted_by = ? ORDER BY FIELD(n.status, 'pending_review', 'scheduled', 'published', 'draft', 'rejected', 'archived'), n.created_at DESC");
        $stmt->execute([(int) $user['id']]);
    }

    return $stmt->fetchAll();
}

function apiAdminYears(): array
{
    return [
        ['value' => 1, 'label' => '1st Year'],
        ['value' => 2, 'label' => '2nd Year'],
        ['value' => 3, 'label' => '3rd Year'],
        ['value' => 4, 'label' => '4th Year'],
    ];
}

function apiAdminPriorities(): array
{
    return [
        'normal' => 'Normal',
        'high' => 'High',
        'critical' => 'Critical',
    ];
}

function apiRecurrenceOptions(): array
{
    return [
        '' => 'One-time template',
        'weekly' => 'Weekly reminder',
        'monthly' => 'Monthly reminder',
        'semester' => 'Each semester',
    ];
}
?>
