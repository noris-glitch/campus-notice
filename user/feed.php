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
$userName = $_SESSION['user_name'];
$targetColumn = getNoticeTargetColumn($pdo) ?: 'faculty_target';

$unreadCount = 0;
try {
    if (featureTableExists($pdo, 'notifications')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        $unreadCount = (int) $stmt->fetchColumn();
    }
} catch (PDOException $e) {
    $unreadCount = 0;
}

$facultyName = $userFaculty ? getFacultyName($pdo, (int) $userFaculty) : '';
$preferences = getUserNotificationPreferences($pdo, $userId);
$subscribedCategories = csvValueToArray($preferences['categories_csv'] ?? '');

$sql = "
    SELECT
        n.*,
        u.name AS author_name,
        EXISTS(SELECT 1 FROM notice_views nv WHERE nv.notice_id = n.id AND nv.user_id = ?) AS has_viewed,
        EXISTS(SELECT 1 FROM notice_acknowledgements na WHERE na.notice_id = n.id AND na.user_id = ? AND na.status = 'acknowledged') AS has_acknowledged,
        (SELECT COUNT(*) FROM notice_questions nq WHERE nq.notice_id = n.id) AS question_count
    FROM notices n
    JOIN users u ON n.posted_by = u.id
    WHERE n.status = 'published'
      AND (n.publish_at IS NULL OR n.publish_at <= NOW())
      AND (n.expire_at IS NULL OR n.expire_at > NOW())
      AND (n.$targetColumn IS NULL OR n.$targetColumn = 0 OR n.$targetColumn = ?)
      AND (n.year_target IS NULL OR n.year_target = 0 OR n.year_target = ?)
    ORDER BY
      n.is_pinned DESC,
      CASE
        WHEN n.requires_acknowledgement = 1 AND NOT EXISTS(
            SELECT 1 FROM notice_acknowledgements na2
            WHERE na2.notice_id = n.id AND na2.user_id = ? AND na2.status = 'acknowledged'
        ) THEN 1
        ELSE 0
      END DESC,
      CASE n.priority
        WHEN 'critical' THEN 3
        WHEN 'high' THEN 2
        ELSE 1
      END DESC,
      EXISTS(SELECT 1 FROM notice_views nv2 WHERE nv2.notice_id = n.id AND nv2.user_id = ?) ASC,
      n.publish_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId, $userId, $userFaculty, $userYear, $userId, $userId]);
$notices = $stmt->fetchAll();

$bookmarkStmt = $pdo->prepare('SELECT notice_id FROM bookmarks WHERE user_id = ?');
$bookmarkStmt->execute([$userId]);
$bookmarks = array_map('intval', array_column($bookmarkStmt->fetchAll(), 'notice_id'));

$categories = getAvailableNoticeCategories();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - JOOUST Campus Notice System</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <style>
        .hero-card,
        .feed-toolbar,
        .empty-state {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .hero-card {
            padding: 24px 28px;
            margin-bottom: 18px;
        }

        .hero-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .pill {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            background: #eef2ff;
            color: #4338ca;
        }

        .feed-toolbar {
            padding: 18px 22px;
            margin-bottom: 18px;
        }

        .search-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }

        .filter-buttons {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .filter-btn {
            padding: 8px 14px;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            background: #f3f4f6;
            color: #374151;
            font-weight: 600;
        }

        .filter-btn.active {
            background: #4338ca;
            color: white;
        }

        .feed-grid {
            display: grid;
            gap: 18px;
        }

        .notice-card {
            background: white;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }

        .notice-top,
        .notice-meta,
        .notice-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
        }

        .notice-badges,
        .notice-stats {
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

        .badge.high,
        .badge.critical {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge.normal {
            background: #ecfeff;
            color: #0f766e;
        }

        .badge.unread {
            background: #fef3c7;
            color: #92400e;
        }

        .badge.ack {
            background: #ede9fe;
            color: #5b21b6;
        }

        .notice-title {
            margin: 12px 0 10px;
            font-size: 1.2rem;
            color: #111827;
        }

        .notice-title a {
            color: inherit;
            text-decoration: none;
        }

        .notice-summary {
            color: #4b5563;
            line-height: 1.7;
            margin-bottom: 14px;
        }

        .notice-meta,
        .notice-stats {
            color: #6b7280;
            font-size: 13px;
        }

        .btn-bookmark,
        .btn-link {
            border: none;
            background: none;
            color: #4338ca;
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
        }

        .notification-dropdown { position: relative; display: inline-block; margin-right: 15px; }
        .notification-bell { background: none; border: none; font-size: 1.5rem; cursor: pointer; position: relative; padding: 5px; }
        .bell-badge { position: absolute; top: -5px; right: -8px; background: #ff4757; color: white; border-radius: 50%; padding: 2px 6px; font-size: 10px; min-width: 18px; text-align: center; }
        .notification-panel { display: none; position: absolute; right: 0; top: 40px; width: min(350px, calc(100vw - 32px)); background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.2); z-index: 1000; max-height: 400px; overflow-y: auto; }
        .notification-panel.show { display: block; }
        .notification-header { padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }
        .notification-item { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; cursor: pointer; display: flex; gap: 10px; }
        .notification-item.unread { background: #f0f2ff; border-left: 3px solid #667eea; }
        .loading { text-align: center; padding: 20px; color: #999; }

        .empty-state {
            padding: 60px 20px;
            text-align: center;
            color: #6b7280;
        }

        @media (max-width: 576px) {
            .hero-card {
                padding: 18px 16px;
            }

            .feed-toolbar {
                padding: 16px;
            }

            .hero-meta,
            .filter-buttons {
                gap: 8px;
            }

            .filter-buttons {
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 4px;
            }

            .filter-btn {
                flex: 0 0 auto;
            }

            .notice-card {
                padding: 16px;
                overflow: hidden;
            }

            .notice-title {
                font-size: 1.05rem;
                line-height: 1.35;
            }

            .notice-summary {
                font-size: 14px;
                line-height: 1.6;
                overflow-wrap: anywhere;
            }

            .notice-meta,
            .notice-actions {
                flex-direction: column;
                align-items: flex-start;
            }

            .notice-stats {
                width: 100%;
                justify-content: space-between;
            }

            .notification-dropdown {
                margin-right: 0;
            }

            .notification-panel {
                position: fixed;
                top: 78px;
                left: 12px;
                right: 12px;
                width: auto;
                max-height: calc(100vh - 110px);
            }

            .notification-header {
                flex-wrap: wrap;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../includes/sidebar.php'; ?>

        <div class="main-content">
            <div class="top-navbar">
                <h2 class="page-title">
                    Notice Feed
                    <?php if ($facultyName): ?>
                        <span class="pill"><?php echo htmlspecialchars(substr($facultyName, 0, 35)); ?></span>
                    <?php endif; ?>
                </h2>
                <div class="user-info">
                    <div class="notification-dropdown" id="notificationDropdown">
                        <button class="notification-bell" onclick="toggleNotificationPanel()">
                            🔔
                            <?php if ($unreadCount > 0): ?>
                                <span class="bell-badge"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notification-panel" id="notificationPanel">
                            <div class="notification-header">
                                <h4>Notifications</h4>
                                <a href="notifications.php" class="view-all">View All</a>
                            </div>
                            <div class="notification-list" id="notificationList">
                                <div class="loading">Loading notifications...</div>
                            </div>
                        </div>
                    </div>
                    <div class="user-avatar"><?php echo strtoupper(substr($userName, 0, 1)); ?></div>
                    <span class="user-name"><?php echo htmlspecialchars($userName); ?></span>
                    <a href="../logout.php" class="logout-btn">Logout</a>
                </div>
            </div>

            <div class="content-area">
                <div class="hero-card">
                    <h3>Personalized Campus Updates</h3>
                    <p>Your feed now prioritizes unread, high-priority, and acknowledgement-required notices so important updates do not get buried.</p>
                    <div class="hero-meta">
                        <span class="pill"><?php echo !empty($preferences['in_app_enabled']) ? 'In-app alerts on' : 'In-app alerts off'; ?></span>
                        <span class="pill"><?php echo !empty($preferences['email_enabled']) ? 'Email alerts on' : 'Email alerts off'; ?></span>
                        <span class="pill"><?php echo empty($subscribedCategories) ? 'All categories' : htmlspecialchars(count($subscribedCategories) . ' category subscriptions'); ?></span>
                        <a href="archive.php" class="btn-link">Browse archive</a>
                    </div>
                </div>

                <div class="feed-toolbar">
                    <input
                        type="text"
                        id="searchInput"
                        class="search-input"
                        placeholder="Search notices by title, content, category, or author"
                        onkeyup="searchNotices()"
                    >
                    <div class="filter-buttons">
                        <button class="filter-btn active" data-category="all" onclick="filterByCategory('all')">All</button>
                        <?php foreach ($categories as $category): ?>
                            <button class="filter-btn" data-category="<?php echo htmlspecialchars($category); ?>" onclick="filterByCategory('<?php echo htmlspecialchars($category); ?>')"><?php echo htmlspecialchars($category); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($notices): ?>
                    <div class="feed-grid" id="feedGrid">
                        <?php foreach ($notices as $notice): ?>
                            <?php
                            $summary = substr($notice['content'], 0, 300);
                            $isBookmarked = in_array((int) $notice['id'], $bookmarks, true);
                            $isUnread = empty($notice['has_viewed']);
                            $needsAck = !empty($notice['requires_acknowledgement']) && empty($notice['has_acknowledged']);
                            ?>
                            <div
                                class="notice-card"
                                data-category="<?php echo htmlspecialchars($notice['category']); ?>"
                                data-title="<?php echo htmlspecialchars(strtolower($notice['title'])); ?>"
                                data-content="<?php echo htmlspecialchars(strtolower($notice['content'])); ?>"
                                data-author="<?php echo htmlspecialchars(strtolower($notice['author_name'])); ?>"
                            >
                                <div class="notice-top">
                                    <div class="notice-badges">
                                        <?php if (!empty($notice['is_pinned'])): ?>
                                            <span class="badge">Pinned</span>
                                        <?php endif; ?>
                                        <?php if ($isUnread): ?>
                                            <span class="badge unread">Unread</span>
                                        <?php endif; ?>
                                        <?php if ($needsAck): ?>
                                            <span class="badge ack">Acknowledge</span>
                                        <?php endif; ?>
                                        <span class="badge <?php echo htmlspecialchars(strtolower($notice['priority'] ?? 'normal')); ?>">
                                            <?php echo htmlspecialchars(ucfirst($notice['priority'] ?? 'normal')); ?>
                                        </span>
                                        <span class="badge"><?php echo htmlspecialchars($notice['category']); ?></span>
                                    </div>
                                    <div class="notice-stats">
                                        <span><?php echo htmlspecialchars($notice['delivery_channels'] ?: 'in_app'); ?></span>
                                    </div>
                                </div>

                                <h3 class="notice-title">
                                    <a href="notice_detail.php?id=<?php echo (int) $notice['id']; ?>"><?php echo htmlspecialchars($notice['title']); ?></a>
                                </h3>

                                <p class="notice-summary">
                                    <?php echo nl2br(htmlspecialchars($summary)); ?><?php echo strlen($notice['content']) > 300 ? '...' : ''; ?>
                                </p>

                                <div class="notice-meta">
                                    <span>By <?php echo htmlspecialchars($notice['author_name']); ?></span>
                                    <span><?php echo date('d M Y, h:i A', strtotime($notice['publish_at'])); ?></span>
                                    <?php if (!empty($notice['acknowledgement_due_at']) && $needsAck): ?>
                                        <span>Ack due <?php echo date('d M Y, h:i A', strtotime($notice['acknowledgement_due_at'])); ?></span>
                                    <?php endif; ?>
                                </div>

                                <div class="notice-actions" style="margin-top: 14px;">
                                    <div class="notice-stats">
                                        <span><?php echo (int) $notice['question_count']; ?> questions</span>
                                    </div>
                                    <div class="notice-stats">
                                        <?php if (!empty($notice['attachment'])): ?>
                                            <a href="../assets/uploads/<?php echo htmlspecialchars($notice['attachment']); ?>" download class="btn-link">Attachment</a>
                                        <?php endif; ?>
                                        <button class="btn-bookmark" onclick="toggleBookmark(<?php echo (int) $notice['id']; ?>, this)">
                                            <?php echo $isBookmarked ? 'Bookmarked' : 'Bookmark'; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <h3>No notices available</h3>
                        <p>There are no published notices for your targeting profile right now.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        let activeCategory = 'all';

        function cardMatchesSearch(card, query) {
            const haystack = [
                card.getAttribute('data-title') || '',
                card.getAttribute('data-content') || '',
                card.getAttribute('data-author') || '',
                card.getAttribute('data-category') || ''
            ].join(' ');

            return haystack.indexOf(query) > -1;
        }

        function updateVisibleCards() {
            const query = (document.getElementById('searchInput').value || '').toLowerCase();
            const cards = document.getElementsByClassName('notice-card');

            for (let i = 0; i < cards.length; i++) {
                const card = cards[i];
                const category = card.getAttribute('data-category');
                const matchesCategory = activeCategory === 'all' || category === activeCategory;
                const matchesSearch = cardMatchesSearch(card, query);
                card.style.display = matchesCategory && matchesSearch ? '' : 'none';
            }
        }

        function searchNotices() {
            updateVisibleCards();
        }

        function filterByCategory(category) {
            activeCategory = category;
            const buttons = document.querySelectorAll('.filter-btn');
            buttons.forEach((button) => {
                button.classList.toggle('active', button.getAttribute('data-category') === category);
            });
            updateVisibleCards();
        }

        function toggleBookmark(noticeId, element) {
            fetch('../ajax/bookmark.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'notice_id=' + noticeId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    element.textContent = data.action === 'added' ? 'Bookmarked' : 'Bookmark';
                }
            });
        }

        function toggleNotificationPanel() {
            const panel = document.getElementById('notificationPanel');
            panel.classList.toggle('show');
            if (panel.classList.contains('show')) {
                loadNotifications();
            }
        }

        function loadNotifications() {
            fetch('../ajax/get_notifications.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayNotifications(data.notifications);
                }
            })
            .catch(() => {
                document.getElementById('notificationList').innerHTML = '<div class="loading">Failed to load notifications</div>';
            });
        }

        function displayNotifications(notifications) {
            const container = document.getElementById('notificationList');
            if (!notifications || notifications.length === 0) {
                container.innerHTML = '<div class="loading">No notifications yet</div>';
                return;
            }

            let html = '';
            notifications.forEach((notif) => {
                html += `<div class="notification-item ${notif.is_read ? '' : 'unread'}" onclick="markAsRead(${notif.id}, ${notif.notice_id})">
                            <div class="notification-icon">📢</div>
                            <div class="notification-content">
                                <div class="notification-title">${escapeHtml(notif.title)}</div>
                                <div class="notification-message">${escapeHtml(notif.message)}</div>
                                <div class="notification-time">${notif.time_ago}</div>
                            </div>
                        </div>`;
            });
            container.innerHTML = html;
        }

        function markAsRead(notificationId, noticeId) {
            fetch('../ajax/mark_notification_read.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.href = `notice_detail.php?id=${noticeId}`;
                }
            });
        }

        function updateBellBadge(count) {
            const bellBadge = document.querySelector('.bell-badge');
            const sidebarBadge = document.querySelector('.notification-badge');
            if (count > 0) {
                if (bellBadge) {
                    bellBadge.textContent = count;
                } else {
                    const bell = document.querySelector('.notification-bell');
                    if (bell && !document.querySelector('.bell-badge')) {
                        const badge = document.createElement('span');
                        badge.className = 'bell-badge';
                        badge.textContent = count;
                        bell.appendChild(badge);
                    }
                }

                if (sidebarBadge) {
                    sidebarBadge.textContent = count;
                }
            } else {
                if (bellBadge) {
                    bellBadge.remove();
                }
                if (sidebarBadge) {
                    sidebarBadge.remove();
                }
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        document.addEventListener('click', function(event) {
            const panel = document.getElementById('notificationPanel');
            const bell = document.querySelector('.notification-bell');
            if (panel && bell && !bell.contains(event.target) && !panel.contains(event.target)) {
                panel.classList.remove('show');
            }
        });

        setInterval(() => {
            fetch('../ajax/get_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateBellBadge(data.unread_count);
                    }
                })
                .catch(() => {});
        }, 30000);
    </script>
</body>
</html>
