<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'defaultdb';
$username = getenv('DB_USER') ?: 'avnadmin';
$password = getenv('DB_PASS') ?: '';
$port = getenv('DB_PORT') ?: '24772';

function isMobileApiRequest(): bool
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return strpos($requestUri, '/ajax/api/') !== false;
}

function emitDatabaseFailure(string $message): void
{
    if (isMobileApiRequest()) {
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: application/json');
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Accept');
        }

        echo json_encode([
            'success' => false,
            'error' => 'The campus service is temporarily unavailable. Please try again in a few minutes.',
            'details' => $message,
        ]);
        exit();
    }

    http_response_code(503);
    die($message);
}

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        PDO::MYSQL_ATTR_SSL_CA       => true,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);

} catch(PDOException $e) {
    emitDatabaseFailure("Connection failed: " . $e->getMessage());
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ... rest of your functions unchanged below ...
// ... rest of your functions stay exactly the same ...

function getFacultyName($pdo, $faculty_id) {
    if (!$faculty_id) return 'All Faculties';
    try {
        $stmt = $pdo->prepare("SELECT name FROM faculties WHERE id = ?");
        $stmt->execute([$faculty_id]);
        $faculty = $stmt->fetch();
        return $faculty ? $faculty['name'] : 'Unknown Faculty';
    } catch (PDOException $e) {
        return 'Unknown Faculty';
    }
}

function getAllFaculties($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM faculties ORDER BY name");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

function getRoleDisplayName($role, $admin_type = null) {
    switch($role) {
        case 'super_admin': return 'Super Administrator';
        case 'admin':
            switch($admin_type) {
                case 'dean_of_students': return 'Dean of Students';
                case 'hod': return 'Head of Faculty';
                case 'student_leader': return 'Student Leader';
                case 'club_leader': return 'Club Leader';
                case 'faculty': return 'Faculty Member';
                default: return 'Administrator';
            }
        case 'student': return 'Student';
        default: return 'User';
    }
}

function getUnreadNotificationCount($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function getUserFaculty($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT f.* FROM faculties f JOIN users u ON u.faculty_id = f.id WHERE u.id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

function logActivity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO user_activity_log (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt->execute([$user_id, $action, $details, $ip]);
    } catch(PDOException $e) {}
}

function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function getNoticeTargetColumn($pdo) {
    if (columnExists($pdo, 'notices', 'faculty_target')) return 'faculty_target';
    if (columnExists($pdo, 'notices', 'department_target')) return 'department_target';
    return null;
}

require_once __DIR__ . '/../includes/feature_helpers.php';
ensureFeatureSchema($pdo);
publishDueScheduledNotices($pdo);
?>
