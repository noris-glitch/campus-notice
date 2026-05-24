<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);

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

function apiIniSizeToBytes(string $value): int
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return 0;
    }

    $unit = strtolower(substr($trimmed, -1));
    $number = (float) $trimmed;

    switch ($unit) {
        case 'g':
            return (int) round($number * 1024 * 1024 * 1024);
        case 'm':
            return (int) round($number * 1024 * 1024);
        case 'k':
            return (int) round($number * 1024);
        default:
            return (int) round((float) $trimmed);
    }
}

function apiRejectOversizedPost(): void
{
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($requestMethod !== 'POST') {
        return;
    }

    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength <= 0) {
        return;
    }

    $maxPostBytes = apiIniSizeToBytes((string) ini_get('post_max_size'));
    if ($maxPostBytes <= 0 || $contentLength <= $maxPostBytes) {
        return;
    }

    $configuredLimit = ini_get('post_max_size') ?: 'the server limit';
    apiRespond(413, [
        'success' => false,
        'error' => 'That upload is too large for the server right now. Please keep shorts under ' . $configuredLimit . '.',
    ]);
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

apiRejectOversizedPost();

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
            f.name AS faculty_name,
            d.name AS department_name
        FROM users u
        LEFT JOIN faculties f ON u.faculty_id = f.id
        LEFT JOIN departments d ON u.department_id = d.id
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
        'department_id' => isset($user['department_id']) ? (int) $user['department_id'] : null,
        'department_name' => $user['department_name'] ?? null,
        'year' => isset($user['year']) ? (int) $user['year'] : null,
        'student_id' => $user['student_id'] ?? null,
        'membership' => $user['membership'] ?? null,
        'phone_number' => $user['phone_number'] ?? null,
        'can_post_shorts' => canUserPostShorts($user) ? 1 : 0,
        'shorts_authorized_by' => isset($user['shorts_authorized_by']) ? (int) $user['shorts_authorized_by'] : null,
        'shorts_authorized_at' => $user['shorts_authorized_at'] ?? null,
        'profile_picture' => $user['profile_picture'] ?? null,
        'profile_picture_url' => !empty($user['profile_picture'])
            ? '/assets/uploads/profiles/' . $user['profile_picture']
            : '/assets/uploads/profiles/default-avatar.png',
        'token' => apiBuildToken($user),
    ];
}

if (!function_exists('canUserPostShorts')) {
    function canUserPostShorts(array $user): bool
    {
        $role = (string) ($user['role'] ?? '');
        if ($role === 'super_admin') {
            return true;
        }

        if ($role !== 'admin' && $role !== 'student') {
            return false;
        }

        return !empty($user['can_post_shorts']);
    }
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

function apiUploadPath(string $relativePath = ''): string
{
    $base = realpath(__DIR__ . '/../../assets');
    if ($base === false) {
        $base = __DIR__ . '/../../assets';
    }

    return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR);
}

function apiEnsureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        apiRespond(500, ['success' => false, 'error' => 'Could not prepare the upload directory']);
    }
}

function apiSanitizeUploadName(string $filename, string $prefix): string
{
    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    $stem = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) pathinfo($filename, PATHINFO_FILENAME));
    $stem = trim((string) $stem, '-');
    $stem = $stem !== '' ? $stem : 'file';

    return $prefix . '_' . time() . '_' . $stem . ($extension !== '' ? '.' . $extension : '');
}

function apiMoveUploadedFile(string $field, string $directory, array $allowedExtensions, string $prefix): ?string
{
    if (!isset($_FILES[$field])) {
        return null;
    }

    $file = $_FILES[$field];
    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        apiRespond(400, ['success' => false, 'error' => 'The uploaded file could not be processed']);
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        apiRespond(400, ['success' => false, 'error' => 'That file type is not allowed']);
    }

    $targetDir = apiUploadPath($directory);
    apiEnsureDirectory($targetDir);

    $newFilename = apiSanitizeUploadName($originalName, $prefix);
    $targetPath = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newFilename;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        apiRespond(500, ['success' => false, 'error' => 'The uploaded file could not be saved']);
    }

    return $newFilename;
}

function apiDeleteUploadedFile(string $relativePath): void
{
    $absolutePath = apiUploadPath($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function apiLocationFeaturesSupported(PDO $pdo): bool
{
    return featureTableExists($pdo, 'user_locations')
        && featureColumnExists($pdo, 'notices', 'latitude')
        && featureColumnExists($pdo, 'notices', 'longitude')
        && featureColumnExists($pdo, 'notices', 'location_name')
        && featureColumnExists($pdo, 'notices', 'radius_km')
        && featureColumnExists($pdo, 'notices', 'event_date');
}

function apiFetchUserLocation(PDO $pdo, int $userId): ?array
{
    if (!featureTableExists($pdo, 'user_locations')) {
        return null;
    }

    $columns = ['latitude', 'longitude', 'location_name', 'updated_at'];
    if (featureColumnExists($pdo, 'user_locations', 'location_address')) {
        $columns[] = 'location_address';
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $columns) . ' FROM user_locations WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $location = $stmt->fetch();

    return $location ?: null;
}

function apiFetchLocationEvents(PDO $pdo, array $user, int $limit = 100): array
{
    $events = apiFetchVisibleNotices($pdo, $user, ['limit' => $limit]);

    return array_values(array_filter($events, static function (array $notice): bool {
        return !empty($notice['latitude']) && !empty($notice['longitude']);
    }));
}

function apiDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371;
    $latDelta = deg2rad($lat2 - $lat1);
    $lngDelta = deg2rad($lng2 - $lng1);

    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function apiFetchNearbyLocationEvents(PDO $pdo, array $user, array $userLocation, int $limit = 50): array
{
    $events = apiFetchLocationEvents($pdo, $user, 200);
    $lat = (float) $userLocation['latitude'];
    $lng = (float) $userLocation['longitude'];
    $nearby = [];

    foreach ($events as $event) {
        $eventLat = isset($event['latitude']) ? (float) $event['latitude'] : null;
        $eventLng = isset($event['longitude']) ? (float) $event['longitude'] : null;
        if ($eventLat === null || $eventLng === null) {
            continue;
        }

        $distance = apiDistanceKm($lat, $lng, $eventLat, $eventLng);
        $radius = isset($event['radius_km']) && $event['radius_km'] !== null
            ? (float) $event['radius_km']
            : 10.0;

        if ($distance <= max($radius, 0.5)) {
            $event['distance'] = round($distance, 3);
            $nearby[] = $event;
        }
    }

    usort($nearby, static function (array $left, array $right): int {
        return ($left['distance'] ?? 0) <=> ($right['distance'] ?? 0);
    });

    return array_slice($nearby, 0, $limit);
}

function apiSaveUserLocation(PDO $pdo, int $userId, float $latitude, float $longitude, ?string $locationName, ?string $locationAddress): void
{
    $hasAddress = featureColumnExists($pdo, 'user_locations', 'location_address');

    if ($hasAddress) {
        $stmt = $pdo->prepare("
            INSERT INTO user_locations (user_id, latitude, longitude, location_name, location_address)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                location_name = VALUES(location_name),
                location_address = VALUES(location_address),
                updated_at = NOW()
        ");
        $stmt->execute([$userId, $latitude, $longitude, $locationName, $locationAddress]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_locations (user_id, latitude, longitude, location_name)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            location_name = VALUES(location_name),
            updated_at = NOW()
    ");
    $stmt->execute([$userId, $latitude, $longitude, $locationName]);
}

function apiNotifyNearbyUsers(PDO $pdo, int $noticeId, float $latitude, float $longitude, ?float $radiusKm, string $title): int
{
    if (!featureTableExists($pdo, 'user_locations')) {
        return 0;
    }

    $effectiveRadius = $radiusKm !== null && $radiusKm > 0 ? $radiusKm : 10.0;
    $hasAddress = featureColumnExists($pdo, 'user_locations', 'location_address');
    $userLocationColumns = $hasAddress ? 'ul.latitude, ul.longitude, ul.location_name, ul.location_address' : 'ul.latitude, ul.longitude, ul.location_name';
    $sql = "
        SELECT u.id AS user_id, u.name, {$userLocationColumns}
        FROM user_locations ul
        JOIN users u ON u.id = ul.user_id
        WHERE u.role = 'student' AND u.is_active = 1
    ";

    $stmt = $pdo->query($sql);
    $targetUsers = $stmt->fetchAll();
    $notified = 0;
    $nearbyInsert = null;
    $notificationInsert = null;

    if (featureTableExists($pdo, 'nearby_notifications')) {
        $nearbyInsert = $pdo->prepare("
            INSERT INTO nearby_notifications (user_id, notice_id, distance_km)
            VALUES (?, ?, ?)
        ");
    }

    if (featureTableExists($pdo, 'notifications')) {
        $notificationInsert = $pdo->prepare("
            INSERT INTO notifications (user_id, notice_id, title, message)
            VALUES (?, ?, ?, ?)
        ");
    }

    foreach ($targetUsers as $targetUser) {
        $distance = apiDistanceKm(
            $latitude,
            $longitude,
            (float) $targetUser['latitude'],
            (float) $targetUser['longitude']
        );

        if ($distance > $effectiveRadius) {
            continue;
        }

        $notified++;

        if ($nearbyInsert) {
            $nearbyInsert->execute([(int) $targetUser['user_id'], $noticeId, round($distance, 3)]);
        }

        if ($notificationInsert && !notificationExistsForNotice($pdo, $noticeId, (int) $targetUser['user_id'])) {
            $message = 'Event near you: "' . $title . '" is happening ' . round($distance, 1) . ' km from your saved campus location.';
            $notificationInsert->execute([(int) $targetUser['user_id'], $noticeId, 'Nearby event alert', $message]);
        }
    }

    return $notified;
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

function apiFetchDepartments(PDO $pdo, ?int $facultyId = null): array
{
    try {
        return fetchDepartments($pdo, $facultyId);
    } catch (PDOException $e) {
        return [];
    }
}

function apiNoticeBaseConditions(PDO $pdo, array $user, bool $includeArchived = false, string $alias = 'n'): array
{
    $conditions = [];
    $params = [];

    if ($includeArchived) {
        $conditions[] = "$alias.status IN ('published', 'archived')";
    } else {
        $conditions[] = "$alias.status = 'published'";
        $conditions[] = "($alias.expire_at IS NULL OR $alias.expire_at > NOW())";
    }

    $conditions[] = "($alias.publish_at IS NULL OR $alias.publish_at <= NOW())";
    [$audienceConditions, $audienceParams] = buildNoticeAudienceConditions($pdo, $alias, $user);
    $conditions = array_merge($conditions, $audienceConditions);
    $params = array_merge($params, $audienceParams);

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
            COALESCE(na.status, '') AS acknowledgement_status,
            (SELECT COUNT(*) FROM notice_views all_nv WHERE all_nv.notice_id = n.id) AS view_count
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
        $notice['view_count'] = isset($notice['view_count']) ? (int) $notice['view_count'] : 0;
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

function apiFetchAdminDashboard(PDO $pdo, array $user, string $analyticsRange = 'weekly'): array
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
            'open_questions' => featureTableExists($pdo, 'notice_questions')
                ? (int) $pdo->query("SELECT COUNT(*) FROM notice_questions WHERE status = 'open'")->fetchColumn()
                : 0,
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

        if (featureTableExists($pdo, 'notice_questions')) {
            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notice_questions nq
                JOIN notices n ON nq.notice_id = n.id
                WHERE n.posted_by = ? AND nq.status = 'open'
            ");
            $countStmt->execute([$userId]);
            $summary['open_questions'] = (int) $countStmt->fetchColumn();
        } else {
            $summary['open_questions'] = 0;
        }

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

    $analytics = ['range' => $analyticsRange, 'series' => []];
    $reports = [
        'total_notices_posted' => (int) ($summary['total_notices'] ?? 0),
        'views_per_notice' => [],
        'most_viewed_notices' => [],
        'user_engagement' => [],
        'department_activity' => [],
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    try {
        $analytics = apiFetchAdminAnalytics($pdo, $user, $analyticsRange);
    } catch (Throwable $e) {
        // Keep dashboard alive even when analytics tables are missing.
    }

    try {
        $reports = apiFetchAdminReports($pdo, $user);
    } catch (Throwable $e) {
        // Keep dashboard alive even when report tables are missing.
    }

    return array_merge($summary, [
        'recent_notices' => $recentNoticeStmt->fetchAll(),
        'recent_students' => $recentStudentStmt->fetchAll(),
        'analytics' => $analytics,
        'reports' => $reports,
    ]);
}

function apiAnalyticsRangeConfig(string $range): array
{
    $normalized = in_array($range, ['daily', 'weekly', 'monthly'], true) ? $range : 'weekly';
    if ($normalized === 'daily') {
        return ['bucket' => 'DATE(ts)', 'format' => 'Y-m-d', 'interval' => 'P6D', 'step' => 'P1D'];
    }
    if ($normalized === 'monthly') {
        return ['bucket' => "DATE_FORMAT(ts, '%Y-%m-01')", 'format' => 'Y-m-d', 'interval' => 'P5M', 'step' => 'P1M'];
    }

    return ['bucket' => "DATE_SUB(DATE(ts), INTERVAL WEEKDAY(ts) DAY)", 'format' => 'Y-m-d', 'interval' => 'P7W', 'step' => 'P1W'];
}

function apiBuildTimelineLabels(string $range): array
{
    $config = apiAnalyticsRangeConfig($range);
    $now = new DateTime('now');
    $start = (clone $now)->sub(new DateInterval($config['interval']));
    $step = new DateInterval($config['step']);
    $labels = [];

    while ($start <= $now) {
        $labels[] = $start->format($config['format']);
        $start->add($step);
    }

    return $labels;
}

function apiUserScopeClause(array $user, string $noticeAlias = 'n', string $userAlias = 'u'): array
{
    if (($user['role'] ?? '') === 'super_admin') {
        return ['', []];
    }

    if (($user['role'] ?? '') === 'admin') {
        return [" WHERE $noticeAlias.posted_by = ? ", [(int) $user['id']]];
    }

    return [' WHERE 1 = 0 ', []];
}

function apiSeriesFromQuery(PDO $pdo, string $sql, array $params, string $range): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['bucket_key']] = (int) $row['total'];
    }

    $labels = apiBuildTimelineLabels($range);
    $points = [];
    foreach ($labels as $label) {
        $points[] = $map[$label] ?? 0;
    }

    return ['labels' => $labels, 'points' => $points];
}

function apiFetchAdminAnalytics(PDO $pdo, array $user, string $range = 'weekly'): array
{
    if (!featureTableExists($pdo, 'user_activity_log')
        || !featureTableExists($pdo, 'notice_views')
        || !featureTableExists($pdo, 'notifications')
        || !featureTableExists($pdo, 'notices')) {
        return [
            'range' => $range,
            'series' => [
                'logins' => ['labels' => [], 'points' => []],
                'notices_viewed' => ['labels' => [], 'points' => []],
                'notices_posted' => ['labels' => [], 'points' => []],
                'notice_downloads' => ['labels' => [], 'points' => []],
                'notice_comments' => ['labels' => [], 'points' => []],
                'active_users' => ['labels' => [], 'points' => []],
                'notifications_read' => ['labels' => [], 'points' => []],
            ],
        ];
    }

    $range = in_array($range, ['daily', 'weekly', 'monthly'], true) ? $range : 'weekly';
    $cfg = apiAnalyticsRangeConfig($range);
    [$noticeScopeClause, $noticeScopeParams] = apiUserScopeClause($user, 'n', 'u');

    $noticePostedSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM notices n
            {$noticeScopeClause}
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $loginSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM user_activity_log ual
            WHERE ual.action IN ('mobile_login', 'login', 'user_login')
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $viewsSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM notice_views nv
            JOIN notices n ON n.id = nv.notice_id
            {$noticeScopeClause}
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $downloadsSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM user_activity_log ual
            WHERE ual.action IN ('notice_attachment_download', 'mobile_notice_attachment_download')
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $commentsSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM notice_questions nq
            JOIN notices n ON n.id = nq.notice_id
            {$noticeScopeClause}
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $activeUsersSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(DISTINCT ual.user_id) AS total
            FROM user_activity_log ual
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    $notifReadSql = "
        SELECT DATE_FORMAT(bucket.bucket_key, '%Y-%m-%d') AS bucket_key, bucket.total
        FROM (
            SELECT {$cfg['bucket']} AS bucket_key, COUNT(*) AS total
            FROM notifications nof
            WHERE nof.is_read = 1
            GROUP BY {$cfg['bucket']}
        ) bucket
    ";

    return [
        'range' => $range,
        'series' => [
            'logins' => apiSeriesFromQuery($pdo, $loginSql, [], $range),
            'notices_viewed' => apiSeriesFromQuery($pdo, $viewsSql, $noticeScopeParams, $range),
            'notices_posted' => apiSeriesFromQuery($pdo, $noticePostedSql, $noticeScopeParams, $range),
            'notice_downloads' => apiSeriesFromQuery($pdo, $downloadsSql, [], $range),
            'notice_comments' => apiSeriesFromQuery($pdo, $commentsSql, $noticeScopeParams, $range),
            'active_users' => apiSeriesFromQuery($pdo, $activeUsersSql, [], $range),
            'notifications_read' => apiSeriesFromQuery($pdo, $notifReadSql, [], $range),
        ],
    ];
}

function apiFetchAdminReports(PDO $pdo, array $user): array
{
    if (!featureTableExists($pdo, 'notices')
        || !featureTableExists($pdo, 'notice_views')
        || !featureTableExists($pdo, 'bookmarks')
        || !featureTableExists($pdo, 'departments')
        || !featureTableExists($pdo, 'users')) {
        return [
            'total_notices_posted' => 0,
            'views_per_notice' => [],
            'most_viewed_notices' => [],
            'user_engagement' => [],
            'department_activity' => [],
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    [$noticeScopeClause, $noticeScopeParams] = apiUserScopeClause($user, 'n', 'u');

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notices n " . $noticeScopeClause);
    $totalStmt->execute($noticeScopeParams);
    $totalNotices = (int) $totalStmt->fetchColumn();

    $viewsPerNoticeStmt = $pdo->prepare("
        SELECT n.id, n.title, COUNT(nv.id) AS views
        FROM notices n
        LEFT JOIN notice_views nv ON nv.notice_id = n.id
        " . $noticeScopeClause . "
        GROUP BY n.id, n.title
        ORDER BY views DESC, n.created_at DESC
        LIMIT 25
    ");
    $viewsPerNoticeStmt->execute($noticeScopeParams);

    $engagementStmt = $pdo->prepare("
        SELECT u.id, u.name, u.email,
               COUNT(DISTINCT nv.id) AS views,
               COUNT(DISTINCT b.id) AS bookmarks,
               COUNT(DISTINCT nq.id) AS comments,
               (COUNT(DISTINCT nv.id) + COUNT(DISTINCT b.id) + COUNT(DISTINCT nq.id)) AS interactions
        FROM users u
        LEFT JOIN notice_views nv ON nv.user_id = u.id
        LEFT JOIN bookmarks b ON b.user_id = u.id
        LEFT JOIN notice_questions nq ON nq.asked_by = u.id
        WHERE u.is_active = 1
        GROUP BY u.id, u.name, u.email
        ORDER BY interactions DESC, u.name ASC
        LIMIT 30
    ");
    $engagementStmt->execute();

    $departmentActivityStmt = $pdo->prepare("
        SELECT d.id, d.name,
               COUNT(DISTINCT n.id) AS notices_posted,
               COUNT(DISTINCT nv.id) AS notice_views,
               COUNT(DISTINCT nq.id) AS notice_comments,
               (COUNT(DISTINCT nv.id) + COUNT(DISTINCT nq.id)) AS engagements
        FROM departments d
        LEFT JOIN notices n ON n.department_id = d.id
        LEFT JOIN notice_views nv ON nv.notice_id = n.id
        LEFT JOIN notice_questions nq ON nq.notice_id = n.id
        GROUP BY d.id, d.name
        ORDER BY engagements DESC, notices_posted DESC, d.name ASC
    ");
    $departmentActivityStmt->execute();

    return [
        'total_notices_posted' => $totalNotices,
        'views_per_notice' => $viewsPerNoticeStmt->fetchAll(),
        'most_viewed_notices' => (function () use ($pdo, $noticeScopeClause, $noticeScopeParams) {
            $stmt = $pdo->prepare("
                SELECT n.id, n.title, COUNT(nv.id) AS views
                FROM notices n
                LEFT JOIN notice_views nv ON nv.notice_id = n.id
                " . $noticeScopeClause . "
                GROUP BY n.id, n.title
                ORDER BY views DESC, n.created_at DESC
                LIMIT 10
            ");
            $stmt->execute($noticeScopeParams);
            return $stmt->fetchAll();
        })(),
        'user_engagement' => $engagementStmt->fetchAll(),
        'department_activity' => $departmentActivityStmt->fetchAll(),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function apiFetchAdminNotices(PDO $pdo, array $user): array
{
    $targetColumn = getNoticeTargetColumn($pdo) ?: 'faculty_target';
    $query = "
        SELECT
            n.*,
            u.name AS author_name,
            f.name AS target_faculty_name,
            d.name AS department_name,
            (SELECT COUNT(*) FROM notice_views nv WHERE nv.notice_id = n.id) AS view_count,
            (SELECT COUNT(*) FROM bookmarks b WHERE b.notice_id = n.id) AS bookmark_count,
            (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id) AS ack_total,
            (SELECT COUNT(*) FROM notice_acknowledgements na WHERE na.notice_id = n.id AND na.status = 'acknowledged') AS ack_done,
            (SELECT COUNT(*) FROM notice_questions nq WHERE nq.notice_id = n.id AND nq.status = 'open') AS open_questions,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'sent') AS email_sent,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'email' AND nd.status = 'failed') AS email_failed,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'sms' AND nd.status = 'sent') AS sms_sent,
            (SELECT COUNT(*) FROM notice_deliveries nd WHERE nd.notice_id = n.id AND nd.channel = 'sms' AND nd.status = 'failed') AS sms_failed
        FROM notices n
        JOIN users u ON n.posted_by = u.id
        LEFT JOIN faculties f ON n.$targetColumn = f.id
        LEFT JOIN departments d ON n.department_id = d.id
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
