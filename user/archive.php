<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if ($_SESSION['user_role'] !== 'student') {
    header('Location: ../super_admin/dashboard.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userFaculty = $_SESSION['faculty_id'] ?? null;
$userYear = $_SESSION['year'] ?? null;
$targetColumn = getNoticeTargetColumn($pdo) ?: 'faculty_target';

$keyword = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');
$priority = trim($_GET['priority'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

$conditions = [
    "n.status IN ('published', 'archived')",
    "(n.publish_at IS NULL OR n.publish_at <= NOW())",
    "(n.$targetColumn IS NULL OR n.$targetColumn = 0 OR n.$targetColumn = ?)",
    "(n.year_target IS NULL OR n.year_target = 0 OR n.year_target = ?)",
];
$params = [$userFaculty, $userYear];

if ($keyword !== '') {
    $conditions[] = '(n.title LIKE ? OR n.content LIKE ? OR u.name LIKE ?)';
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($category !== '') {
    $conditions[] = 'n.category = ?';
    $params[] = $category;
}

if ($priority !== '') {
    $conditions[] = 'n.priority = ?';
    $params[] = $priority;
}

if ($statusFilter === 'current') {
    $conditions[] = '(n.expire_at IS NULL OR n.expire_at > NOW())';
} elseif ($statusFilter === 'expired') {
    $conditions[] = 'n.expire_at IS NOT NULL AND n.expire_at <= NOW()';
}

if ($dateFrom !== '') {
    $conditions[] = 'DATE(n.publish_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $conditions[] = 'DATE(n.publish_at) <= ?';
    $params[] = $dateTo;
}

$sql = "
    SELECT
        n.*,
        u.name AS author_name,
        EXISTS(SELECT 1 FROM notice_views nv WHERE nv.notice_id = n.id AND nv.user_id = ?) AS has_viewed
    FROM notices n
    JOIN users u ON n.posted_by = u.id
    WHERE " . implode(' AND ', $conditions) . "
    ORDER BY n.publish_at DESC
";

array_unshift($params, $userId);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$notices = $stmt->fetchAll();

$categories = getAvailableNoticeCategories();
$priorities = ['normal' => 'Normal', 'high' => 'High', 'critical' => 'Critical'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notice Archive - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .panel {
            background: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 18px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .filter-grid input,
        .filter-grid select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
        }

        .archive-list {
            display: grid;
            gap: 16px;
        }

        .archive-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .badges,
        .meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: #eef2ff;
            color: #4338ca;
        }

        .badge.expired { background: #fee2e2; color: #b91c1c; }
        .badge.current { background: #e8fff2; color: #047857; }
        .badge.unread { background: #fef3c7; color: #92400e; }

        .meta {
            color: #6b7280;
            font-size: 13px;
            margin-top: 10px;
        }

        .btn-primary,
        .btn-secondary {
            border: none;
            border-radius: 6px;
            padding: 10px 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-secondary {
            background: #e8eefc;
            color: #243b7a;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">Notice Archive</h2>
                <div class="user-info">
                    <div class="user-avatar"><?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?></div>
                    <span><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <div class="panel">
                    <form method="GET">
                        <div class="filter-grid">
                            <input type="text" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="Keyword or author">
                            <select name="category">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" <?php echo $category === $option ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="priority">
                                <option value="">All priorities</option>
                                <?php foreach ($priorities as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $priority === $value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select name="status">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All notices</option>
                                <option value="current" <?php echo $statusFilter === 'current' ? 'selected' : ''; ?>>Current only</option>
                                <option value="expired" <?php echo $statusFilter === 'expired' ? 'selected' : ''; ?>>Expired only</option>
                            </select>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                            <button type="submit" class="btn-primary">Search Archive</button>
                            <a href="archive.php" class="btn-secondary">Reset Filters</a>
                        </div>
                    </form>
                </div>

                <?php if ($notices): ?>
                    <div class="archive-list">
                        <?php foreach ($notices as $notice): ?>
                            <?php $isExpired = !empty($notice['expire_at']) && strtotime($notice['expire_at']) <= time(); ?>
                            <div class="archive-card">
                                <div class="badges">
                                    <span class="badge"><?php echo htmlspecialchars($notice['category'] ?: 'General'); ?></span>
                                    <span class="badge"><?php echo htmlspecialchars(ucfirst($notice['priority'] ?? 'normal')); ?></span>
                                    <span class="badge <?php echo $isExpired ? 'expired' : 'current'; ?>"><?php echo $isExpired ? 'Expired' : 'Current'; ?></span>
                                    <?php if (empty($notice['has_viewed'])): ?>
                                        <span class="badge unread">Unread</span>
                                    <?php endif; ?>
                                </div>
                                <h3 style="margin:14px 0 10px;"><a href="notice_detail.php?id=<?php echo (int) $notice['id']; ?>" style="color:#111827; text-decoration:none;"><?php echo htmlspecialchars($notice['title']); ?></a></h3>
                                <p style="color:#4b5563; line-height:1.7;"><?php echo nl2br(htmlspecialchars(substr($notice['content'], 0, 260))); ?><?php echo strlen($notice['content']) > 260 ? '...' : ''; ?></p>
                                <div class="meta">
                                    <span>By <?php echo htmlspecialchars($notice['author_name']); ?></span>
                                    <span>Published <?php echo date('d M Y, h:i A', strtotime($notice['publish_at'])); ?></span>
                                    <?php if (!empty($notice['expire_at'])): ?>
                                        <span>Expired <?php echo date('d M Y', strtotime($notice['expire_at'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="panel">
                        <h3>No archive results</h3>
                        <p style="color:#6b7280;">Try widening your filters or removing the keyword search.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
