<?php
/**
 * Bounded append-only audit trail for storage / snapshot / migration events.
 *
 * @package Eko_Sampa
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Eko_Sampa_Storage_Audit {

    public const OPTION_KEY = 'eko_sampa_storage_audit_log';

    private const MAX_ENTRIES = 80;

    /**
     * @param array<string, mixed> $context
     */
    public static function append(string $event, array $context = []): void {
        $entry = [
            'at'    => gmdate('c'),
            'event' => sanitize_key($event),
            'user'  => get_current_user_id(),
            'ctx'   => $context,
        ];

        $list = get_option(self::OPTION_KEY, []);
        if (! is_array($list)) {
            $list = [];
        }
        $list[] = $entry;
        if (count($list) > self::MAX_ENTRIES) {
            $list = array_slice($list, -self::MAX_ENTRIES);
        }
        update_option(self::OPTION_KEY, $list, false);

        /**
         * Fires after a storage audit entry was persisted.
         *
         * @param array<string, mixed> $entry
         */
        do_action('eko_sampa_storage_audit_appended', $entry);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function read_last(int $limit = 30): array {
        $list = get_option(self::OPTION_KEY, []);
        if (! is_array($list)) {
            return [];
        }

        return array_slice($list, -max(1, min(self::MAX_ENTRIES, $limit)));
    }
}
