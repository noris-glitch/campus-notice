<?php

if (!function_exists('featureTableExists')) {
    function featureTableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('featureColumnExists')) {
    function featureColumnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('ensureFeatureSchema')) {
    function ensureFeatureSchema(PDO $pdo): void
    {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        $initialized = true;

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_notification_preferences` (
                `user_id` INT UNSIGNED NOT NULL,
                `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `emergency_override` TINYINT(1) NOT NULL DEFAULT 1,
                `quiet_hours_start` TIME NULL,
                `quiet_hours_end` TIME NULL,
                `categories_csv` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                CONSTRAINT `fk_user_notification_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notice_acknowledgements` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `notice_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
                `acknowledged_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_notice_acknowledgements_notice_user` (`notice_id`, `user_id`),
                KEY `idx_notice_acknowledgements_notice` (`notice_id`),
                KEY `idx_notice_acknowledgements_user` (`user_id`),
                CONSTRAINT `fk_notice_acknowledgements_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_notice_acknowledgements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notice_questions` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `notice_id` INT UNSIGNED NOT NULL,
                `asked_by` INT UNSIGNED NOT NULL,
                `question` TEXT NOT NULL,
                `answer` TEXT NULL,
                `answered_by` INT UNSIGNED NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'open',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `answered_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_notice_questions_notice` (`notice_id`),
                KEY `idx_notice_questions_asked_by` (`asked_by`),
                CONSTRAINT `fk_notice_questions_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_notice_questions_asked_by` FOREIGN KEY (`asked_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notice_templates` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `title` VARCHAR(255) NOT NULL,
                `content` LONGTEXT NOT NULL,
                `category` VARCHAR(80) NULL,
                `faculty_target` INT UNSIGNED NULL,
                `year_target` TINYINT UNSIGNED NULL,
                `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
                `default_priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
                `requires_acknowledgement` TINYINT(1) NOT NULL DEFAULT 0,
                `delivery_channels` VARCHAR(100) NOT NULL DEFAULT 'in_app',
                `is_recurring` TINYINT(1) NOT NULL DEFAULT 0,
                `recurrence_pattern` VARCHAR(100) NULL,
                `created_by` INT UNSIGNED NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_notice_templates_created_by` (`created_by`),
                CONSTRAINT `fk_notice_templates_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `notice_deliveries` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `notice_id` INT UNSIGNED NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `channel` VARCHAR(30) NOT NULL,
                `destination` VARCHAR(255) NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
                `error_message` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `sent_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `ux_notice_deliveries_notice_user_channel` (`notice_id`, `user_id`, `channel`),
                KEY `idx_notice_deliveries_notice` (`notice_id`),
                KEY `idx_notice_deliveries_user` (`user_id`),
                CONSTRAINT `fk_notice_deliveries_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_notice_deliveries_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_locations` (
                `user_id` INT UNSIGNED NOT NULL,
                `latitude` DECIMAL(10,7) NOT NULL,
                `longitude` DECIMAL(10,7) NOT NULL,
                `location_name` VARCHAR(255) NULL,
                `location_address` VARCHAR(255) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                CONSTRAINT `fk_user_locations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `nearby_notifications` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `notice_id` INT UNSIGNED NOT NULL,
                `distance_km` DECIMAL(7,3) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_nearby_notifications_user` (`user_id`),
                KEY `idx_nearby_notifications_notice` (`notice_id`),
                CONSTRAINT `fk_nearby_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_nearby_notifications_notice` FOREIGN KEY (`notice_id`) REFERENCES `notices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $columns = [
            'priority' => "ALTER TABLE `notices` ADD COLUMN `priority` VARCHAR(20) NOT NULL DEFAULT 'normal' AFTER `category`",
            'requires_acknowledgement' => "ALTER TABLE `notices` ADD COLUMN `requires_acknowledgement` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_pinned`",
            'acknowledgement_due_at' => "ALTER TABLE `notices` ADD COLUMN `acknowledgement_due_at` DATETIME NULL AFTER `requires_acknowledgement`",
            'approval_status' => "ALTER TABLE `notices` ADD COLUMN `approval_status` VARCHAR(20) NOT NULL DEFAULT 'approved' AFTER `status`",
            'review_notes' => "ALTER TABLE `notices` ADD COLUMN `review_notes` TEXT NULL AFTER `approval_status`",
            'reviewed_by' => "ALTER TABLE `notices` ADD COLUMN `reviewed_by` INT UNSIGNED NULL AFTER `review_notes`",
            'reviewed_at' => "ALTER TABLE `notices` ADD COLUMN `reviewed_at` DATETIME NULL AFTER `reviewed_by`",
            'delivery_channels' => "ALTER TABLE `notices` ADD COLUMN `delivery_channels` VARCHAR(100) NOT NULL DEFAULT 'in_app' AFTER `reviewed_at`",
            'template_id' => "ALTER TABLE `notices` ADD COLUMN `template_id` INT UNSIGNED NULL AFTER `delivery_channels`",
            'recurrence_pattern' => "ALTER TABLE `notices` ADD COLUMN `recurrence_pattern` VARCHAR(100) NULL AFTER `template_id`",
            'latitude' => "ALTER TABLE `notices` ADD COLUMN `latitude` DECIMAL(10,7) NULL AFTER `expire_at`",
            'longitude' => "ALTER TABLE `notices` ADD COLUMN `longitude` DECIMAL(10,7) NULL AFTER `latitude`",
            'location_name' => "ALTER TABLE `notices` ADD COLUMN `location_name` VARCHAR(255) NULL AFTER `longitude`",
            'location_address' => "ALTER TABLE `notices` ADD COLUMN `location_address` VARCHAR(255) NULL AFTER `location_name`",
            'radius_km' => "ALTER TABLE `notices` ADD COLUMN `radius_km` DECIMAL(7,3) NULL AFTER `location_address`",
            'event_date' => "ALTER TABLE `notices` ADD COLUMN `event_date` DATETIME NULL AFTER `radius_km`",
            'event_end_date' => "ALTER TABLE `notices` ADD COLUMN `event_end_date` DATETIME NULL AFTER `event_date`"
        ];

        foreach ($columns as $column => $sql) {
            if (!featureColumnExists($pdo, 'notices', $column)) {
                $pdo->exec($sql);
            }
        }

        if (!featureColumnExists($pdo, 'user_locations', 'location_address')) {
            $pdo->exec("ALTER TABLE `user_locations` ADD COLUMN `location_address` VARCHAR(255) NULL AFTER `location_name`");
        }
    }
}

if (!function_exists('getAvailableNoticeCategories')) {
    function getAvailableNoticeCategories(): array
    {
        return ['Academic', 'Event', 'Exam', 'Placement', 'LostFound', 'General'];
    }
}

if (!function_exists('normalizeDeliveryChannels')) {
    function normalizeDeliveryChannels($rawChannels): array
    {
        $channels = is_array($rawChannels) ? $rawChannels : explode(',', (string) $rawChannels);
        $channels = array_map('trim', $channels);
        $channels = array_values(array_unique(array_filter($channels)));
        $allowed = ['in_app', 'email'];

        $filtered = array_values(array_intersect($channels, $allowed));
        if (empty($filtered)) {
            return ['in_app'];
        }

        return $filtered;
    }
}

if (!function_exists('csvValueToArray')) {
    function csvValueToArray(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $value));
        return array_values(array_filter($parts));
    }
}

if (!function_exists('arrayToCsvValue')) {
    function arrayToCsvValue(array $values): string
    {
        $clean = array_values(array_unique(array_filter(array_map('trim', $values))));
        return implode(',', $clean);
    }
}

if (!function_exists('ensureNotificationPreferenceRow')) {
    function ensureNotificationPreferenceRow(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO user_notification_preferences (user_id, categories_csv)
            VALUES (?, ?)
        ");
        $stmt->execute([$userId, arrayToCsvValue(getAvailableNoticeCategories())]);
    }
}

if (!function_exists('getUserNotificationPreferences')) {
    function getUserNotificationPreferences(PDO $pdo, int $userId): array
    {
        ensureNotificationPreferenceRow($pdo, $userId);

        $stmt = $pdo->prepare("SELECT * FROM user_notification_preferences WHERE user_id = ?");
        $stmt->execute([$userId]);
        $prefs = $stmt->fetch();

        if (!$prefs) {
            return [
                'user_id' => $userId,
                'in_app_enabled' => 1,
                'email_enabled' => 0,
                'emergency_override' => 1,
                'quiet_hours_start' => null,
                'quiet_hours_end' => null,
                'categories_csv' => arrayToCsvValue(getAvailableNoticeCategories()),
            ];
        }

        return $prefs;
    }
}

if (!function_exists('saveUserNotificationPreferences')) {
    function saveUserNotificationPreferences(PDO $pdo, int $userId, array $data): void
    {
        ensureNotificationPreferenceRow($pdo, $userId);

        $stmt = $pdo->prepare("
            UPDATE user_notification_preferences
            SET in_app_enabled = ?,
                email_enabled = ?,
                emergency_override = ?,
                quiet_hours_start = ?,
                quiet_hours_end = ?,
                categories_csv = ?
            WHERE user_id = ?
        ");

        $stmt->execute([
            !empty($data['in_app_enabled']) ? 1 : 0,
            !empty($data['email_enabled']) ? 1 : 0,
            !empty($data['emergency_override']) ? 1 : 0,
            $data['quiet_hours_start'] ?: null,
            $data['quiet_hours_end'] ?: null,
            arrayToCsvValue($data['categories'] ?? getAvailableNoticeCategories()),
            $userId,
        ]);
    }
}

if (!function_exists('isUrgentNotice')) {
    function isUrgentNotice(array $notice): bool
    {
        $priority = strtolower((string) ($notice['priority'] ?? 'normal'));
        return in_array($priority, ['high', 'critical'], true) || !empty($notice['requires_acknowledgement']);
    }
}

if (!function_exists('isWithinQuietHours')) {
    function isWithinQuietHours(?string $start, ?string $end): bool
    {
        if (!$start || !$end) {
            return false;
        }

        $now = new DateTime('now');
        $current = ((int) $now->format('H') * 60) + (int) $now->format('i');

        [$startHour, $startMinute] = array_map('intval', explode(':', substr($start, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', substr($end, 0, 5)));

        $startMinutes = ($startHour * 60) + $startMinute;
        $endMinutes = ($endHour * 60) + $endMinute;

        if ($startMinutes === $endMinutes) {
            return false;
        }

        if ($startMinutes < $endMinutes) {
            return $current >= $startMinutes && $current < $endMinutes;
        }

        return $current >= $startMinutes || $current < $endMinutes;
    }
}

if (!function_exists('userAllowsNoticeCategory')) {
    function userAllowsNoticeCategory(array $prefs, ?string $category): bool
    {
        $selected = csvValueToArray($prefs['categories_csv'] ?? '');
        if (empty($selected)) {
            return true;
        }

        if ($category === null || $category === '') {
            return true;
        }

        return in_array($category, $selected, true);
    }
}

if (!function_exists('shouldCreateInAppNotification')) {
    function shouldCreateInAppNotification(array $prefs, array $notice): bool
    {
        if (!empty($prefs['in_app_enabled'])) {
            return userAllowsNoticeCategory($prefs, $notice['category'] ?? null) || (isUrgentNotice($notice) && !empty($prefs['emergency_override']));
        }

        return isUrgentNotice($notice) && !empty($prefs['emergency_override']);
    }
}

if (!function_exists('shouldSendEmailNotification')) {
    function shouldSendEmailNotification(array $prefs, array $notice): bool
    {
        if (empty($prefs['email_enabled'])) {
            return false;
        }

        $urgent = isUrgentNotice($notice);
        $allowedCategory = userAllowsNoticeCategory($prefs, $notice['category'] ?? null);

        if (!$allowedCategory && !$urgent) {
            return false;
        }

        if (isWithinQuietHours($prefs['quiet_hours_start'] ?? null, $prefs['quiet_hours_end'] ?? null) && !$urgent) {
            return false;
        }

        return true;
    }
}

if (!function_exists('getNoticeById')) {
    function getNoticeById(PDO $pdo, int $noticeId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT n.*, u.name AS author_name
            FROM notices n
            JOIN users u ON n.posted_by = u.id
            WHERE n.id = ?
            LIMIT 1
        ");
        $stmt->execute([$noticeId]);
        $notice = $stmt->fetch();

        return $notice ?: null;
    }
}

if (!function_exists('getNoticeTargetUsers')) {
    function getNoticeTargetUsers(PDO $pdo, array $notice): array
    {
        $targetColumn = featureColumnExists($pdo, 'notices', 'faculty_target') ? 'faculty_target' : 'department_target';
        $sql = "SELECT id, name, email FROM users WHERE role = 'student' AND is_active = 1";
        $params = [];

        if (!empty($notice[$targetColumn])) {
            $sql .= " AND faculty_id = ?";
            $params[] = $notice[$targetColumn];
        }

        if (!empty($notice['year_target'])) {
            $sql .= " AND year = ?";
            $params[] = $notice['year_target'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}

if (!function_exists('recordNoticeAcknowledgementAudience')) {
    function recordNoticeAcknowledgementAudience(PDO $pdo, array $notice, array $targetUsers): void
    {
        if (empty($notice['requires_acknowledgement']) || empty($targetUsers)) {
            return;
        }

        $stmt = $pdo->prepare("
            INSERT IGNORE INTO notice_acknowledgements (notice_id, user_id, status)
            VALUES (?, ?, 'pending')
        ");

        foreach ($targetUsers as $user) {
            $stmt->execute([(int) $notice['id'], (int) $user['id']]);
        }
    }
}

if (!function_exists('notificationExistsForNotice')) {
    function notificationExistsForNotice(PDO $pdo, int $noticeId, int $userId): bool
    {
        $stmt = $pdo->prepare("SELECT id FROM notifications WHERE notice_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$noticeId, $userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('createInAppNoticeNotification')) {
    function createInAppNoticeNotification(PDO $pdo, array $user, array $notice): void
    {
        if (notificationExistsForNotice($pdo, (int) $notice['id'], (int) $user['id'])) {
            return;
        }

        $priority = strtoupper((string) ($notice['priority'] ?? 'normal'));
        $prefix = $priority !== 'NORMAL' ? '[' . $priority . '] ' : '';
        $message = 'A new notice "' . $notice['title'] . '" is available.';

        if (!empty($notice['requires_acknowledgement'])) {
            $message .= ' This notice requires acknowledgement.';
        }

        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, notice_id, title, message)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([
            (int) $user['id'],
            (int) $notice['id'],
            $prefix . 'New Notice Posted',
            $message,
        ]);
    }
}

if (!function_exists('deliveryRecordExists')) {
    function deliveryRecordExists(PDO $pdo, int $noticeId, int $userId, string $channel): bool
    {
        $stmt = $pdo->prepare("
            SELECT id FROM notice_deliveries
            WHERE notice_id = ? AND user_id = ? AND channel = ?
            LIMIT 1
        ");
        $stmt->execute([$noticeId, $userId, $channel]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('storeDeliveryResult')) {
    function storeDeliveryResult(PDO $pdo, int $noticeId, int $userId, string $channel, ?string $destination, string $status, ?string $error = null): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO notice_deliveries (notice_id, user_id, channel, destination, status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                destination = VALUES(destination),
                status = VALUES(status),
                error_message = VALUES(error_message),
                sent_at = VALUES(sent_at)
        ");

        $sentAt = in_array($status, ['sent', 'skipped'], true) ? date('Y-m-d H:i:s') : null;

        $stmt->execute([
            $noticeId,
            $userId,
            $channel,
            $destination,
            $status,
            $error,
            $sentAt,
        ]);
    }
}

if (!function_exists('buildNoticeEmailBody')) {
    function buildNoticeEmailBody(array $notice, array $user): string
    {
        $lines = [
            'Hello ' . ($user['name'] ?: 'Student') . ',',
            '',
            'A new campus notice has been published for you.',
            '',
            'Title: ' . ($notice['title'] ?? 'Campus Notice'),
            'Category: ' . ($notice['category'] ?? 'General'),
            'Priority: ' . ucfirst((string) ($notice['priority'] ?? 'normal')),
            'Published: ' . date('d M Y, h:i A', strtotime((string) ($notice['publish_at'] ?? 'now'))),
            '',
            trim((string) ($notice['content'] ?? '')),
        ];

        if (!empty($notice['requires_acknowledgement'])) {
            $lines[] = '';
            $lines[] = 'This notice requires acknowledgement in the JOOUST Campus Notice System.';
        }

        return implode(PHP_EOL, $lines);
    }
}

if (!function_exists('sendNoticeEmail')) {
    function sendNoticeEmail(string $recipientEmail, string $subject, string $body): array
    {
        if (!function_exists('mail')) {
            return ['success' => false, 'error' => 'mail() is not available on this server.'];
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: JOOUST Notice System <noreply@campus-notice.local>',
        ];

        $sent = @mail($recipientEmail, $subject, $body, implode("\r\n", $headers));

        if ($sent) {
            return ['success' => true, 'error' => null];
        }

        return ['success' => false, 'error' => 'mail() returned false. SMTP may not be configured.'];
    }
}

if (!function_exists('deliverNoticeToAudience')) {
    function deliverNoticeToAudience(PDO $pdo, int $noticeId): array
    {
        $notice = getNoticeById($pdo, $noticeId);
        if (!$notice || ($notice['status'] ?? '') !== 'published') {
            return ['users' => 0, 'in_app' => 0, 'email_sent' => 0, 'email_failed' => 0];
        }

        $targetUsers = getNoticeTargetUsers($pdo, $notice);
        $channels = normalizeDeliveryChannels($notice['delivery_channels'] ?? 'in_app');

        recordNoticeAcknowledgementAudience($pdo, $notice, $targetUsers);

        $summary = [
            'users' => count($targetUsers),
            'in_app' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
        ];

        foreach ($targetUsers as $user) {
            $prefs = getUserNotificationPreferences($pdo, (int) $user['id']);

            if (in_array('in_app', $channels, true) && shouldCreateInAppNotification($prefs, $notice)) {
                createInAppNoticeNotification($pdo, $user, $notice);
                $summary['in_app']++;
            }

            if (in_array('email', $channels, true)) {
                if (deliveryRecordExists($pdo, (int) $notice['id'], (int) $user['id'], 'email')) {
                    continue;
                }

                if (!shouldSendEmailNotification($prefs, $notice)) {
                    storeDeliveryResult(
                        $pdo,
                        (int) $notice['id'],
                        (int) $user['id'],
                        'email',
                        $user['email'] ?? null,
                        'skipped',
                        'User preferences or quiet hours prevented email delivery.'
                    );
                    continue;
                }

                $emailResult = sendNoticeEmail(
                    (string) $user['email'],
                    'JOOUST Notice: ' . ($notice['title'] ?? 'Campus Notice'),
                    buildNoticeEmailBody($notice, $user)
                );

                if ($emailResult['success']) {
                    storeDeliveryResult($pdo, (int) $notice['id'], (int) $user['id'], 'email', $user['email'], 'sent');
                    $summary['email_sent']++;
                } else {
                    storeDeliveryResult($pdo, (int) $notice['id'], (int) $user['id'], 'email', $user['email'], 'failed', $emailResult['error']);
                    $summary['email_failed']++;
                }
            }
        }

        return $summary;
    }
}

if (!function_exists('publishDueScheduledNotices')) {
    function publishDueScheduledNotices(PDO $pdo): void
    {
        if (!featureTableExists($pdo, 'notices')) {
            return;
        }

        $stmt = $pdo->query("
            SELECT id
            FROM notices
            WHERE status = 'scheduled'
              AND publish_at <= NOW()
            ORDER BY publish_at ASC
        ");

        $dueNotices = $stmt->fetchAll();
        if (empty($dueNotices)) {
            return;
        }

        $update = $pdo->prepare("UPDATE notices SET status = 'published' WHERE id = ? AND status = 'scheduled'");
        foreach ($dueNotices as $noticeRow) {
            $noticeId = (int) $noticeRow['id'];
            $update->execute([$noticeId]);
            deliverNoticeToAudience($pdo, $noticeId);
        }
    }
}

if (!function_exists('saveNoticeTemplate')) {
    function saveNoticeTemplate(PDO $pdo, array $data): int
    {
        $stmt = $pdo->prepare("
            INSERT INTO notice_templates (
                name, title, content, category, faculty_target, year_target,
                is_pinned, default_priority, requires_acknowledgement,
                delivery_channels, is_recurring, recurrence_pattern, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $data['name'],
            $data['title'],
            $data['content'],
            $data['category'],
            $data['faculty_target'] ?: null,
            $data['year_target'] ?: null,
            !empty($data['is_pinned']) ? 1 : 0,
            $data['priority'] ?? 'normal',
            !empty($data['requires_acknowledgement']) ? 1 : 0,
            arrayToCsvValue(normalizeDeliveryChannels($data['delivery_channels'] ?? ['in_app'])),
            !empty($data['is_recurring']) ? 1 : 0,
            $data['recurrence_pattern'] ?: null,
            (int) $data['created_by'],
        ]);

        return (int) $pdo->lastInsertId();
    }
}

if (!function_exists('fetchVisibleTemplates')) {
    function fetchVisibleTemplates(PDO $pdo, int $userId, string $role): array
    {
        if (!featureTableExists($pdo, 'notice_templates')) {
            return [];
        }

        if ($role === 'super_admin') {
            $stmt = $pdo->query("
                SELECT nt.*, u.name AS author_name
                FROM notice_templates nt
                JOIN users u ON nt.created_by = u.id
                ORDER BY nt.updated_at DESC
            ");
            return $stmt->fetchAll();
        }

        $stmt = $pdo->prepare("
            SELECT nt.*, u.name AS author_name
            FROM notice_templates nt
            JOIN users u ON nt.created_by = u.id
            WHERE nt.created_by = ? OR u.role = 'super_admin'
            ORDER BY nt.updated_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('getTemplateById')) {
    function getTemplateById(PDO $pdo, int $templateId): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM notice_templates WHERE id = ? LIMIT 1");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        return $template ?: null;
    }
}

if (!function_exists('resolveFacultyId')) {
    function resolveFacultyId(PDO $pdo, ?string $rawValue): ?int
    {
        $value = trim((string) $rawValue);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $stmt = $pdo->prepare("SELECT id FROM faculties WHERE LOWER(name) = LOWER(?) LIMIT 1");
        $stmt->execute([$value]);
        $result = $stmt->fetchColumn();
        return $result ? (int) $result : null;
    }
}

if (!function_exists('saveNoticeReviewDecision')) {
    function saveNoticeReviewDecision(PDO $pdo, int $noticeId, int $reviewerId, string $decision, ?string $notes = null): ?array
    {
        $notice = getNoticeById($pdo, $noticeId);
        if (!$notice) {
            return null;
        }

        $decision = strtolower($decision);
        $publishAt = $notice['publish_at'] ?? date('Y-m-d H:i:s');
        $futurePublish = strtotime((string) $publishAt) > time();

        if ($decision === 'approve') {
            $newStatus = $futurePublish ? 'scheduled' : 'published';
            $stmt = $pdo->prepare("
                UPDATE notices
                SET approval_status = 'approved',
                    status = ?,
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $notes ?: null, $reviewerId, $noticeId]);

            if ($newStatus === 'published') {
                deliverNoticeToAudience($pdo, $noticeId);
            }

            return ['status' => $newStatus, 'decision' => 'approved'];
        }

        if ($decision === 'reject') {
            $stmt = $pdo->prepare("
                UPDATE notices
                SET approval_status = 'rejected',
                    status = 'rejected',
                    review_notes = ?,
                    reviewed_by = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$notes ?: null, $reviewerId, $noticeId]);
            return ['status' => 'rejected', 'decision' => 'rejected'];
        }

        return null;
    }
}
