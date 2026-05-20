<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(405, ['success' => false, 'error' => 'Method not allowed']);
}

$userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
$token = trim((string) ($_GET['token'] ?? ''));

if ($userId <= 0 || $token === '') {
    respond(400, ['success' => false, 'error' => 'Missing authentication details']);
}

try {
    $userStmt = $pdo->prepare('SELECT id, name, email, role, faculty_id, year, is_active FROM users WHERE id = ? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1) {
        respond(401, ['success' => false, 'error' => 'Invalid user']);
    }

    $decodedToken = base64_decode($token, true);
    $expectedToken = $user['id'] . ':' . $user['email'];

    if ($decodedToken === false || $decodedToken !== $expectedToken) {
        respond(401, ['success' => false, 'error' => 'Invalid token']);
    }

    $targetColumn = getNoticeTargetColumn($pdo) ?: 'faculty_target';
    $params = [];
    $whereClauses = [
        "n.status = 'published'",
        '(n.publish_at IS NULL OR n.publish_at <= NOW())',
        '(n.expire_at IS NULL OR n.expire_at > NOW())',
    ];

    if ($user['role'] === 'student') {
        $whereClauses[] = "(n.$targetColumn IS NULL OR n.$targetColumn = 0 OR n.$targetColumn = ?)";
        $whereClauses[] = '(n.year_target IS NULL OR n.year_target = 0 OR n.year_target = ?)';
        $params[] = $user['faculty_id'];
        $params[] = $user['year'];
    }

    $sql = "
        SELECT
            n.id,
            n.title,
            n.content,
            n.category,
            n.priority,
            n.is_pinned,
            COALESCE(n.publish_at, n.created_at) AS created_at,
            u.name AS author_name
        FROM notices n
        JOIN users u ON n.posted_by = u.id
        WHERE " . implode(' AND ', $whereClauses) . "
        ORDER BY n.is_pinned DESC, COALESCE(n.publish_at, n.created_at) DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notices = $stmt->fetchAll();

    respond(200, [
        'success' => true,
        'notices' => $notices,
    ]);
} catch (PDOException $e) {
    respond(500, ['success' => false, 'error' => 'Unable to load notices right now']);
}
?>
