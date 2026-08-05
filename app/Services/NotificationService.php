<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use Throwable;

final class NotificationService
{
    public static function toUser(
        int $userId,
        string $category,
        string $title,
        string $message,
        string $priority = 'normal',
        ?string $link = null
    ): void {
        self::insert($userId, null, $category, $title, $message, $priority, $link);
    }

    /** Broadcast to every administrator without fanning out one row per admin. */
    public static function toAdministrators(
        string $category,
        string $title,
        string $message,
        string $priority = 'normal',
        ?string $link = null
    ): void {
        self::insert(null, 'administrator', $category, $title, $message, $priority, $link);
    }

    private static function insert(
        ?int $userId,
        ?string $roleTarget,
        string $category,
        string $title,
        string $message,
        string $priority,
        ?string $link
    ): void {
        try {
            $id = Database::instance()->insert('notifications', [
                'user_id'     => $userId,
                'role_target' => $roleTarget,
                'category'    => $category,
                'title'       => mb_substr($title, 0, 150),
                'message'     => mb_substr($message, 0, 1000),
                'link'        => $link,
                'priority'    => in_array($priority, ['low', 'normal', 'high', 'critical'], true) ? $priority : 'normal',
                'created_at'  => Clock::nowString(),
            ]);

            $channel = $roleTarget === 'administrator' ? 'dashboard.admin' : 'user.' . $userId;

            RealtimeService::publish($channel, 'system.notification', [
                'notification_id' => (int) $id,
                'category'        => $category,
                'title'           => $title,
                'message'         => $message,
                'priority'        => $priority,
                'link'            => $link,
            ]);
        } catch (Throwable $e) {
            Logger::error('Notification write failed', ['error' => $e->getMessage(), 'title' => $title]);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId, string $role, int $limit = 25, bool $unreadOnly = false): array
    {
        $sql = 'SELECT * FROM notifications
                 WHERE (user_id = :user_id OR (user_id IS NULL AND role_target = :role))';

        if ($unreadOnly) {
            $sql .= ' AND is_read = 0';
        }

        $sql .= ' ORDER BY notification_id DESC LIMIT ' . max(1, min($limit, 100));

        return Database::instance()->select($sql, ['user_id' => $userId, 'role' => $role]);
    }

    public static function unreadCount(int $userId, string $role): int
    {
        return (int) Database::instance()->scalar(
            'SELECT COUNT(*) FROM notifications
              WHERE is_read = 0 AND (user_id = :user_id OR (user_id IS NULL AND role_target = :role))',
            ['user_id' => $userId, 'role' => $role]
        );
    }

    public static function markRead(int $notificationId, int $userId, string $role): void
    {
        Database::instance()->execute(
            'UPDATE notifications
                SET is_read = 1, read_at = :now
              WHERE notification_id = :id
                AND (user_id = :user_id OR (user_id IS NULL AND role_target = :role))',
            ['id' => $notificationId, 'user_id' => $userId, 'role' => $role, 'now' => Clock::nowString()]
        );
    }

    public static function markAllRead(int $userId, string $role): int
    {
        return Database::instance()->execute(
            'UPDATE notifications
                SET is_read = 1, read_at = :now
              WHERE is_read = 0 AND (user_id = :user_id OR (user_id IS NULL AND role_target = :role))',
            ['user_id' => $userId, 'role' => $role, 'now' => Clock::nowString()]
        );
    }

    /**
     * Groups notifications into Today / Yesterday / Earlier for the
     * notification centre (Part 7).
     *
     * @param  list<array<string,mixed>> $notifications
     * @return array<string,list<array<string,mixed>>>
     */
    public static function group(array $notifications): array
    {
        $today     = Clock::today();
        $yesterday = Clock::now()->modify('-1 day')->format('Y-m-d');
        $grouped   = ['Today' => [], 'Yesterday' => [], 'Earlier' => []];

        foreach ($notifications as $notification) {
            $date = substr((string) $notification['created_at'], 0, 10);

            $bucket = match ($date) {
                $today     => 'Today',
                $yesterday => 'Yesterday',
                default    => 'Earlier',
            };

            $grouped[$bucket][] = $notification;
        }

        return array_filter($grouped, static fn (array $items): bool => $items !== []);
    }

    /** Retention sweep (180 days, Part 5). Read notifications only — unread ones persist. */
    public static function purgeOld(int $days): int
    {
        return Database::instance()->execute(
            'DELETE FROM notifications
              WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)',
            ['days' => $days]
        );
    }
}
