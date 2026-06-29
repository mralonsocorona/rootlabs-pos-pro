<?php

namespace MXPOSPro\Database;

defined('ABSPATH') || exit;

class Migrator
{
    public static function run(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $current = get_option('mx_pos_pro_db_version', '');

        if ($current !== MX_POS_PRO_DB_VERSION || ! self::tables_exist() || self::needs_1_5_repair() || self::needs_1_6_repair() || self::needs_1_7_repair() || self::needs_1_8_repair() || self::needs_1_9_repair() || self::needs_1_10_repair() || self::needs_1_11_repair() || self::needs_1_12_repair()) {
            self::migrate_from($current);
        }
    }

    public static function maybe_migrate(): void
    {
        $current = get_option('mx_pos_pro_db_version', '');

        if ($current !== MX_POS_PRO_DB_VERSION || ! self::tables_exist() || self::needs_1_5_repair() || self::needs_1_6_repair() || self::needs_1_7_repair() || self::needs_1_8_repair() || self::needs_1_9_repair() || self::needs_1_10_repair() || self::needs_1_11_repair() || self::needs_1_12_repair()) {
            self::run();
        }
    }

    private static function migrate_from(string $from_version): void
    {
        self::sync_schema();

        if ($from_version === '1.0' || $from_version === '') {
            self::migrate_to_1_1();
        }

        if ($from_version === '1.1' || $from_version === '1.0' || $from_version === '') {
            self::migrate_to_1_2();
        }

        if (in_array($from_version, ['1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_3();
        }

        if (in_array($from_version, ['1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_4();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_5();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_6();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_7();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.7', '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_8();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.8', '1.7', '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_9();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.9', '1.8', '1.7', '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_10();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.10', '1.9', '1.8', '1.7', '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_11();
        }

        if (in_array($from_version, [MX_POS_PRO_DB_VERSION, '1.11', '1.10', '1.9', '1.8', '1.7', '1.6', '1.5', '1.4', '1.3', '1.2', '1.1', '1.0', ''], true)) {
            self::migrate_to_1_12();
        }

        require_once MX_POS_PRO_INCLUDES . 'Core/Capabilities.php';
        \MXPOSPro\Core\Capabilities::install();

        if (self::is_1_12_complete()) {
            update_option('mx_pos_pro_db_version', MX_POS_PRO_DB_VERSION, false);
        }
    }

    private static function sync_schema(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = Schema::get_tables();
        $sql    = implode("\n\n", $tables);

        dbDelta($sql);
    }

    private static function migrate_to_1_3(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_cash_cuts';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found === $table) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED AUTO_INCREMENT,
            session_id      BIGINT UNSIGNED NOT NULL,
            cut_type        VARCHAR(5)      NOT NULL,
            sequence        INT UNSIGNED    NOT NULL DEFAULT 1,
            summary_json    LONGTEXT        NOT NULL,
            generated_by    BIGINT UNSIGNED NOT NULL,
            generated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_final        TINYINT(1)      NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY          session_id (session_id),
            KEY          generated_at (generated_at)
        ) {$collate};";

        dbDelta($sql);
    }

    private static function migrate_to_1_4(): void
    {
        global $wpdb;

        self::migrate_cash_cuts_unique_index($wpdb);
        self::migrate_cash_movements_client_request_id($wpdb);
    }

    private static function migrate_cash_cuts_unique_index($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_cash_cuts';

        $index_found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'unique_final_z'",
                $table
            )
        );

        if ((int) $index_found > 0) {
            return;
        }

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        $sql = "ALTER TABLE `{$table}` ADD UNIQUE KEY unique_final_z (session_id, is_final)";

        $wpdb->query($sql);
    }

    private static function migrate_cash_movements_client_request_id($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_cash_movements';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        $col_found = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                'client_request_id'
            )
        );

        if ($col_found === null || $col_found === '') {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN client_request_id VARCHAR(100) DEFAULT NULL AFTER created_by"
            );
        }

        $index_found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'client_request_id'",
                $table
            )
        );

        if ((int) $index_found === 0) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD UNIQUE KEY client_request_id (client_request_id)"
            );
        }
    }

    private static function migrate_to_1_1(): void
    {
        global $wpdb;

        $sales_table = $wpdb->prefix . 'mx_pos_sales';
        $found       = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$sales_table}` LIKE %s",
                'refunded_total'
            )
        );

        if ($found === null || $found === '') {
            $wpdb->query(
                "ALTER TABLE `{$sales_table}` ADD COLUMN refunded_total DECIMAL(19,4) NOT NULL DEFAULT 0.0000 AFTER total"
            );
        }
    }

    private static function migrate_to_1_2(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_sessions';

        $columns = [
            'closed_by'          => 'BIGINT UNSIGNED DEFAULT NULL AFTER difference',
            'close_note'         => 'VARCHAR(500) DEFAULT NULL AFTER closed_by',
            'denominations_json' => 'LONGTEXT DEFAULT NULL AFTER close_note',
        ];

        foreach ($columns as $col => $definition) {
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SHOW COLUMNS FROM `{$table}` LIKE %s",
                    $col
                )
            );

            if ($found === null || $found === '') {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD COLUMN {$col} {$definition}"
                );
            }
        }
    }

    private static function migrate_to_1_5(): void
    {
        global $wpdb;

        if (! self::has_required_1_5_tables() || ! self::has_required_1_5_columns()) {
            return;
        }

        self::ensure_1_5_seed_data($wpdb);

        if (! self::has_required_1_5_seed_data()) {
            return;
        }

        self::ensure_1_5_backfill($wpdb);
        self::log_schema_upgraded($wpdb);
    }

    private static function ensure_1_5_seed_data($wpdb): void
    {
        self::seed_default_branch($wpdb);
        self::seed_default_register($wpdb);
        self::seed_default_payment_methods($wpdb);
    }

    private static function seed_default_branch($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_branches';

        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s LIMIT 1", 'main')
        );

        if ($id) {
            $wpdb->update(
                $table,
                [
                    'name'       => 'Sucursal Principal',
                    'is_active'  => 1,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $id],
                ['%s', '%d', '%s'],
                ['%d']
            );

            return;
        }

        $wpdb->insert(
            $table,
            [
                'name'       => 'Sucursal Principal',
                'slug'       => 'main',
                'is_active'  => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%d', '%s', '%s']
        );
    }

    private static function seed_default_register($wpdb): void
    {
        $registers_table = $wpdb->prefix . 'mx_pos_registers';
        $branches_table  = $wpdb->prefix . 'mx_pos_branches';

        $branch_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$branches_table}` WHERE slug = %s LIMIT 1", 'main')
        );

        if (! $branch_id) {
            return;
        }

        $id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$registers_table}` WHERE slug = %s LIMIT 1", 'main')
        );

        if ($id) {
            $wpdb->update(
                $registers_table,
                [
                    'branch_id'  => (int) $branch_id,
                    'name'       => 'Caja Principal',
                    'is_active'  => 1,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => (int) $id],
                ['%d', '%s', '%d', '%s'],
                ['%d']
            );

            return;
        }

        $wpdb->insert(
            $registers_table,
            [
                'branch_id'  => (int) $branch_id,
                'name'       => 'Caja Principal',
                'slug'       => 'main',
                'is_active'  => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
        );
    }

    private static function seed_default_payment_methods($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_payment_methods';

        $methods = [
            ['slug' => 'cash',  'name' => 'Efectivo', 'affects_cash_register' => 1, 'sort_order' => 1],
            ['slug' => 'card',  'name' => 'Tarjeta',  'affects_cash_register' => 0, 'sort_order' => 2],
            ['slug' => 'mixed', 'name' => 'Mixto',    'affects_cash_register' => 1, 'sort_order' => 3],
        ];

        foreach ($methods as $m) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s LIMIT 1", $m['slug'])
            );

            if ($exists) {
                $wpdb->update(
                    $table,
                    [
                        'name'                  => $m['name'],
                        'affects_cash_register' => $m['affects_cash_register'],
                        'is_active'             => 1,
                        'sort_order'            => $m['sort_order'],
                        'updated_at'            => current_time('mysql'),
                    ],
                    ['id' => (int) $exists],
                    ['%s', '%d', '%d', '%d', '%s'],
                    ['%d']
                );

                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'name'                  => $m['name'],
                    'slug'                  => $m['slug'],
                    'affects_cash_register' => $m['affects_cash_register'],
                    'is_active'             => 1,
                    'sort_order'            => $m['sort_order'],
                    'created_at'            => current_time('mysql'),
                    'updated_at'            => current_time('mysql'),
                ],
                ['%s', '%s', '%d', '%d', '%d', '%s', '%s']
            );
        }
    }

    private static function ensure_1_5_backfill($wpdb): void
    {
        $branches_table  = $wpdb->prefix . 'mx_pos_branches';
        $registers_table = $wpdb->prefix . 'mx_pos_registers';

        $branch_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$branches_table}` WHERE slug = %s LIMIT 1", 'main')
        );

        $register_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM `{$registers_table}` WHERE slug = %s LIMIT 1", 'main')
        );

        if (! $branch_id || ! $register_id) {
            return;
        }

        $branch_id   = (int) $branch_id;
        $register_id = (int) $register_id;

        $sessions_table = $wpdb->prefix . 'mx_pos_sessions';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$sessions_table}`
                 SET pos_register_id = %d
                 WHERE pos_register_id IS NULL",
                $register_id
            )
        );

        $sales_table = $wpdb->prefix . 'mx_pos_sales';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$sales_table}`
                 SET branch_id = IF(branch_id IS NULL, %d, branch_id),
                     pos_register_id = IF(pos_register_id IS NULL, %d, pos_register_id)
                 WHERE branch_id IS NULL OR pos_register_id IS NULL",
                $branch_id,
                $register_id
            )
        );

        $movements_table = $wpdb->prefix . 'mx_pos_cash_movements';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$movements_table}`
                 SET branch_id = IF(branch_id IS NULL, %d, branch_id),
                     pos_register_id = IF(pos_register_id IS NULL, %d, pos_register_id)
                 WHERE branch_id IS NULL OR pos_register_id IS NULL",
                $branch_id,
                $register_id
            )
        );

        $cuts_table = $wpdb->prefix . 'mx_pos_cash_cuts';
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$cuts_table}`
                 SET branch_id = IF(branch_id IS NULL, %d, branch_id),
                     pos_register_id = IF(pos_register_id IS NULL, %d, pos_register_id)
                 WHERE branch_id IS NULL OR pos_register_id IS NULL",
                $branch_id,
                $register_id
            )
        );
    }

    private static function log_schema_upgraded($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $wpdb->insert(
            $table,
            [
                'actor_id'     => get_current_user_id() > 0 ? get_current_user_id() : null,
                'action'       => 'schema_upgraded',
                'entity_type'  => 'schema',
                'entity_id'    => null,
                'ip_address'   => $ipAddress,
                'context_data' => wp_json_encode([
                    'from_version' => get_option('mx_pos_pro_db_version', ''),
                    'to_version'   => MX_POS_PRO_DB_VERSION,
                ]),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private static function needs_1_5_repair(): bool
    {
        return ! self::has_required_1_5_tables()
            || ! self::has_required_1_5_columns()
            || ! self::has_required_1_5_seed_data();
    }

    private static function is_1_5_complete(): bool
    {
        return self::tables_exist()
            && self::has_required_1_5_tables()
            && self::has_required_1_5_columns()
            && self::has_required_1_5_seed_data();
    }

    private static function has_required_1_5_tables(): bool
    {
        foreach ([
            'mx_pos_branches',
            'mx_pos_registers',
            'mx_pos_employees',
            'mx_pos_payment_methods',
            'mx_pos_order_payments',
        ] as $table) {
            if (! self::table_exists($table)) {
                return false;
            }
        }

        return true;
    }

    private static function has_required_1_5_columns(): bool
    {
        $required = [
            'mx_pos_sessions'       => ['pos_register_id'],
            'mx_pos_sales'          => ['branch_id', 'pos_register_id', 'pos_employee_id'],
            'mx_pos_cash_movements' => ['branch_id', 'pos_register_id', 'pos_employee_id'],
            'mx_pos_cash_cuts'      => ['branch_id', 'pos_register_id', 'pos_employee_id'],
            'mx_pos_audit_logs'     => ['branch_id', 'pos_register_id', 'pos_employee_id'],
        ];

        foreach ($required as $table => $columns) {
            foreach ($columns as $column) {
                if (! self::column_exists($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function has_required_1_5_seed_data(): bool
    {
        global $wpdb;

        if (! self::has_required_1_5_tables()) {
            return false;
        }

        $branches_table = $wpdb->prefix . 'mx_pos_branches';
        $branch_id      = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$branches_table}` WHERE slug = %s AND is_active = 1 LIMIT 1",
                'main'
            )
        );

        if (! $branch_id) {
            return false;
        }

        $registers_table = $wpdb->prefix . 'mx_pos_registers';
        $register_id     = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$registers_table}` WHERE slug = %s AND branch_id = %d AND is_active = 1 LIMIT 1",
                'main',
                (int) $branch_id
            )
        );

        if (! $register_id) {
            return false;
        }

        $methods_table = $wpdb->prefix . 'mx_pos_payment_methods';
        $methods       = [
            'cash'  => 1,
            'card'  => 0,
            'mixed' => 1,
        ];

        foreach ($methods as $slug => $affects_cash_register) {
            $method_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM `{$methods_table}`
                     WHERE slug = %s
                       AND affects_cash_register = %d
                       AND is_active = 1
                     LIMIT 1",
                    $slug,
                    $affects_cash_register
                )
            );

            if (! $method_id) {
                return false;
            }
        }

        return true;
    }

    private static function migrate_to_1_6(): void
    {
        global $wpdb;

        if (self::has_1_6_columns() && self::has_1_6_backfill()) {
            return;
        }

        self::ensure_1_6_session_columns($wpdb);
        self::ensure_1_6_backfill($wpdb);
        self::log_schema_upgraded($wpdb);
    }

    private static function needs_1_6_repair(): bool
    {
        return ! self::has_1_6_columns()
            || ! self::has_1_6_backfill();
    }

    private static function is_1_6_complete(): bool
    {
        return self::is_1_5_complete()
            && self::has_1_6_columns()
            && self::has_1_6_backfill();
    }

    private static function has_1_6_columns(): bool
    {
        return self::column_exists('mx_pos_sessions', 'branch_id')
            && self::column_exists('mx_pos_sessions', 'pos_employee_id');
    }

    private static function has_1_6_backfill(): bool
    {
        global $wpdb;

        if (! self::has_1_6_columns()) {
            return false;
        }

        $branches_table = $wpdb->prefix . 'mx_pos_branches';
        $sessions_table = $wpdb->prefix . 'mx_pos_sessions';

        $branch_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$branches_table}` WHERE slug = %s AND is_active = 1 LIMIT 1",
                'main'
            )
        );

        if (! $branch_id) {
            return true;
        }

        $null_branch = $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$sessions_table}` WHERE branch_id IS NULL"
        );

        return (int) $null_branch === 0;
    }

    private static function ensure_1_6_session_columns($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_sessions';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        if (! self::column_exists('mx_pos_sessions', 'branch_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN branch_id BIGINT UNSIGNED DEFAULT NULL AFTER pos_register_id"
            );
        }

        if (! self::column_exists('mx_pos_sessions', 'pos_employee_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN pos_employee_id BIGINT UNSIGNED DEFAULT NULL AFTER branch_id"
            );
        }

        $index_found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'branch_id'",
                $table
            )
        );

        if ((int) $index_found === 0) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD KEY branch_id (branch_id)"
            );
        }

        $index_found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'pos_employee_id'",
                $table
            )
        );

        if ((int) $index_found === 0) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD KEY pos_employee_id (pos_employee_id)"
            );
        }
    }

    private static function ensure_1_6_backfill($wpdb): void
    {
        if (! self::has_1_6_columns()) {
            return;
        }

        $branches_table  = $wpdb->prefix . 'mx_pos_branches';
        $registers_table = $wpdb->prefix . 'mx_pos_registers';

        $branch_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$branches_table}` WHERE slug = %s LIMIT 1",
                'main'
            )
        );

        $register_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$registers_table}` WHERE slug = %s LIMIT 1",
                'main'
            )
        );

        $sessions_table = $wpdb->prefix . 'mx_pos_sessions';

        if ($branch_id) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$sessions_table}`
                     SET branch_id = %d
                     WHERE branch_id IS NULL",
                    (int) $branch_id
                )
            );
        }

        if ($register_id) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE `{$sessions_table}`
                     SET pos_register_id = %d
                     WHERE pos_register_id IS NULL",
                    (int) $register_id
                )
            );
        }
    }

    private static function migrate_to_1_7(): void
    {
        global $wpdb;

        if (self::has_1_7_columns()) {
            return;
        }

        self::ensure_1_7_session_columns($wpdb);
        self::log_schema_upgraded($wpdb);
    }

    private static function needs_1_7_repair(): bool
    {
        return ! self::has_1_7_columns();
    }

    private static function is_1_7_complete(): bool
    {
        return self::is_1_6_complete()
            && self::has_1_7_columns();
    }

    private static function has_1_7_columns(): bool
    {
        return self::column_exists('mx_pos_sessions', 'voided_at')
            && self::column_exists('mx_pos_sessions', 'voided_by')
            && self::column_exists('mx_pos_sessions', 'void_reason');
    }

    private static function ensure_1_7_session_columns($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_sessions';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        if (! self::column_exists('mx_pos_sessions', 'voided_at')) {
            $after = self::column_exists('mx_pos_sessions', 'closed_at')
                ? 'AFTER closed_at'
                : '';
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN voided_at DATETIME DEFAULT NULL {$after}"
            );
        }

        if (! self::column_exists('mx_pos_sessions', 'voided_by')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN voided_by BIGINT UNSIGNED DEFAULT NULL AFTER voided_at"
            );
        }

        if (! self::column_exists('mx_pos_sessions', 'void_reason')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN void_reason VARCHAR(500) DEFAULT NULL AFTER voided_by"
            );
        }
    }

    private static function migrate_to_1_8(): void
    {
        global $wpdb;

        if (self::has_1_8_columns()) {
            self::seed_1_8_payment_methods($wpdb);
            return;
        }

        $table = $wpdb->prefix . 'mx_pos_payment_methods';

        $cols = [
            'payment_type'     => "ALTER TABLE `{$table}` ADD COLUMN payment_type VARCHAR(20) NOT NULL DEFAULT 'other' AFTER slug",
            'allow_reference'  => "ALTER TABLE `{$table}` ADD COLUMN allow_reference TINYINT(1) NOT NULL DEFAULT 0 AFTER affects_cash_register",
            'card_fee_enabled' => "ALTER TABLE `{$table}` ADD COLUMN card_fee_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_reference",
            'card_fee_type'    => "ALTER TABLE `{$table}` ADD COLUMN card_fee_type VARCHAR(20) DEFAULT NULL AFTER card_fee_enabled",
            'card_fee_value'   => "ALTER TABLE `{$table}` ADD COLUMN card_fee_value DECIMAL(10,4) DEFAULT NULL AFTER card_fee_type",
            'wc_gateway_id'    => "ALTER TABLE `{$table}` ADD COLUMN wc_gateway_id VARCHAR(100) DEFAULT NULL AFTER card_fee_value",
        ];

        $added = false;

        foreach ($cols as $col => $sql) {
            if (! self::column_exists('mx_pos_payment_methods', $col)) {
                $wpdb->query($sql);
                $added = true;
            }
        }

        if ($added) {
            self::log_schema_upgraded($wpdb);
        }

        self::seed_1_8_payment_methods($wpdb);
    }

    private static function seed_1_8_payment_methods($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_payment_methods';

        $methods = [
            ['slug' => 'cash',  'name' => 'Efectivo', 'payment_type' => 'cash',  'affects_cash_register' => 1, 'allow_reference' => 0, 'is_active' => 1, 'sort_order' => 1],
            ['slug' => 'card',  'name' => 'Tarjeta',  'payment_type' => 'card',  'affects_cash_register' => 0, 'allow_reference' => 1, 'is_active' => 1, 'sort_order' => 2],
            ['slug' => 'mixed', 'name' => 'Mixto',    'payment_type' => 'mixed', 'affects_cash_register' => 1, 'allow_reference' => 0, 'is_active' => 1, 'sort_order' => 3],
        ];

        foreach ($methods as $m) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s LIMIT 1", $m['slug'])
            );

            if ($exists) {
                $wpdb->update(
                    $table,
                    [
                        'name'                  => $m['name'],
                        'payment_type'          => $m['payment_type'],
                        'affects_cash_register' => $m['affects_cash_register'],
                        'allow_reference'       => $m['allow_reference'],
                        'is_active'             => $m['is_active'],
                        'sort_order'            => $m['sort_order'],
                        'updated_at'            => current_time('mysql'),
                    ],
                    ['id' => (int) $exists],
                    ['%s', '%s', '%d', '%d', '%d', '%d', '%s'],
                    ['%d']
                );
                continue;
            }

            $wpdb->insert(
                $table,
                [
                    'name'                  => $m['name'],
                    'slug'                  => $m['slug'],
                    'payment_type'          => $m['payment_type'],
                    'affects_cash_register' => $m['affects_cash_register'],
                    'allow_reference'       => $m['allow_reference'],
                    'is_active'             => $m['is_active'],
                    'sort_order'            => $m['sort_order'],
                    'created_at'            => current_time('mysql'),
                    'updated_at'            => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s']
            );
        }
    }

    private static function needs_1_8_repair(): bool
    {
        return ! self::has_1_8_columns();
    }

    private static function is_1_8_complete(): bool
    {
        return self::is_1_7_complete()
            && self::has_1_8_columns();
    }

    private static function has_1_8_columns(): bool
    {
        return self::column_exists('mx_pos_payment_methods', 'payment_type')
            && self::column_exists('mx_pos_payment_methods', 'allow_reference')
            && self::column_exists('mx_pos_payment_methods', 'card_fee_enabled');
    }

    private static function migrate_to_1_9(): void
    {
        global $wpdb;

        if (self::has_1_9_columns()) {
            return;
        }

        $table = $wpdb->prefix . 'mx_pos_sales';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        if (! self::column_exists('mx_pos_sales', 'client_request_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN client_request_id VARCHAR(100) DEFAULT NULL AFTER status"
            );
        }

        $index_found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'client_request_id'",
                $table
            )
        );

        if ((int) $index_found === 0) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD KEY client_request_id (client_request_id)"
            );
        }

        self::log_schema_upgraded($wpdb);
    }

    private static function needs_1_9_repair(): bool
    {
        return ! self::has_1_9_columns();
    }

    private static function is_1_9_complete(): bool
    {
        return self::is_1_8_complete()
            && self::has_1_9_columns();
    }

    private static function has_1_9_columns(): bool
    {
        return self::column_exists('mx_pos_sales', 'client_request_id');
    }

    private static function migrate_to_1_10(): void
    {
        global $wpdb;

        if (self::is_1_10_complete()) {
            return;
        }

        $salesTable = $wpdb->prefix . 'mx_pos_sales';

        if (self::table_exists('mx_pos_sales')) {
            $isUnique = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = %s
                       AND INDEX_NAME = 'client_request_id'
                       AND NON_UNIQUE = 0",
                    $salesTable
                )
            );

            if ((int) $isUnique === 0) {
                self::deduplicate_client_request_ids($wpdb, $salesTable);

                $hasKey = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = %s
                           AND INDEX_NAME = 'client_request_id'",
                        $salesTable
                    )
                );

                if ((int) $hasKey > 0) {
                    $wpdb->query("ALTER TABLE `{$salesTable}` DROP KEY client_request_id");
                }

                $wpdb->query("ALTER TABLE `{$salesTable}` ADD UNIQUE KEY client_request_id (client_request_id)");
            }
        }

        $paymentsTable = $wpdb->prefix . 'mx_pos_order_payments';

        if (self::table_exists('mx_pos_order_payments')) {
            if (! self::column_exists('mx_pos_order_payments', 'client_request_id')) {
                $wpdb->query(
                    "ALTER TABLE `{$paymentsTable}` ADD COLUMN client_request_id VARCHAR(100) DEFAULT NULL AFTER status"
                );
            }

            $paymentsIndex = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = %s
                       AND INDEX_NAME = 'client_request_id'",
                    $paymentsTable
                )
            );

            if ((int) $paymentsIndex === 0) {
                $wpdb->query(
                    "ALTER TABLE `{$paymentsTable}` ADD UNIQUE KEY client_request_id (client_request_id)"
                );
            }
        }

        self::log_schema_upgraded($wpdb);
    }

    private static function deduplicate_client_request_ids(object $wpdb, string $table): void
    {
        $duplicates = $wpdb->get_results(
            "SELECT client_request_id, COUNT(*) AS cnt
             FROM `{$table}`
             WHERE client_request_id IS NOT NULL AND client_request_id != ''
             GROUP BY client_request_id
             HAVING cnt > 1",
            ARRAY_A
        );

        if (empty($duplicates)) {
            return;
        }

        foreach ($duplicates as $dup) {
            $crid = $dup['client_request_id'];

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE client_request_id = %s ORDER BY id ASC",
                    $crid
                ),
                ARRAY_A
            );

            if (count($rows) <= 1) {
                continue;
            }

            $first = true;
            foreach ($rows as $row) {
                if ($first) {
                    $first = false;
                    continue;
                }

                $newValue = $crid . '-legacy-' . $row['id'];

                $wpdb->update(
                    $table,
                    ['client_request_id' => $newValue],
                    ['id' => $row['id']],
                    ['%s'],
                    ['%d']
                );
            }
        }
    }

    private static function needs_1_10_repair(): bool
    {
        return ! self::is_1_10_complete();
    }

    private static function is_1_10_complete(): bool
    {
        return self::is_1_9_complete()
            && self::has_1_10_sales_index()
            && self::has_1_10_payments_column();
    }

    private static function has_1_10_sales_index(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_sales';

        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = 'client_request_id'
                   AND NON_UNIQUE = 0",
                $table
            )
        );

        return (int) $found > 0;
    }

    private static function has_1_10_payments_column(): bool
    {
        return self::column_exists('mx_pos_order_payments', 'client_request_id');
    }

    private static function migrate_to_1_11(): void
    {
        global $wpdb;

        if (self::is_1_11_complete()) {
            return;
        }

        $table = $wpdb->prefix . 'mx_pos_product_index';

        if (! self::table_exists('mx_pos_product_index')) {
            return;
        }

        $columns = [
            'object_id'        => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER id',
            'parent_id'        => 'BIGINT UNSIGNED DEFAULT NULL AFTER variation_id',
            'catalog_group_id' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER parent_id',
            'sku_normalized'   => "VARCHAR(100) NOT NULL DEFAULT '' AFTER sku",
            'name_normalized'  => "VARCHAR(255) NOT NULL DEFAULT '' AFTER name",
            'parent_name'      => "VARCHAR(255) NOT NULL DEFAULT '' AFTER name_normalized",
            'variation_label'  => "VARCHAR(255) NOT NULL DEFAULT '' AFTER parent_name",
            'is_purchasable'   => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
            'display_price'    => 'DECIMAL(19,4) DEFAULT NULL AFTER sale_price',
            'min_price'        => 'DECIMAL(19,4) DEFAULT NULL AFTER display_price',
            'max_price'        => 'DECIMAL(19,4) DEFAULT NULL AFTER min_price',
            'image_url'        => 'TEXT DEFAULT NULL AFTER max_price',
            'image_alt'        => "VARCHAR(255) NOT NULL DEFAULT '' AFTER image_url",
            'image_version'    => "VARCHAR(50) NOT NULL DEFAULT '' AFTER image_alt",
            'index_generation' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER searchable_text',
        ];

        foreach ($columns as $column => $definition) {
            if (! self::column_exists('mx_pos_product_index', $column)) {
                $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN {$column} {$definition}");
            }
        }

        $wpdb->query(
            "UPDATE `{$table}`
             SET object_id = CASE WHEN variation_id IS NULL THEN product_id ELSE variation_id END
             WHERE object_id = 0"
        );

        $wpdb->query(
            "UPDATE `{$table}`
             SET catalog_group_id = product_id
             WHERE catalog_group_id = 0"
        );

        $wpdb->query(
            "UPDATE `{$table}`
             SET parent_id = product_id
             WHERE parent_id IS NULL AND variation_id IS NOT NULL"
        );

        $indexes = [
            'object_id'          => 'ADD KEY object_id (object_id)',
            'sku_normalized'     => 'ADD KEY sku_normalized (sku_normalized)',
            'name_normalized'    => 'ADD KEY name_normalized (name_normalized)',
            'catalog_group_id'   => 'ADD KEY catalog_group_id (catalog_group_id)',
            'status_stock_group' => 'ADD KEY status_stock_group (status, stock_status, catalog_group_id)',
            'index_generation'   => 'ADD KEY index_generation (index_generation)',
        ];

        foreach ($indexes as $index => $definition) {
            if (! self::index_exists('mx_pos_product_index', $index)) {
                $wpdb->query("ALTER TABLE `{$table}` {$definition}");
            }
        }

        self::log_schema_upgraded($wpdb);
    }

    private static function needs_1_11_repair(): bool
    {
        return ! self::is_1_11_complete();
    }

    private static function is_1_11_complete(): bool
    {
        $columns = [
            'object_id',
            'parent_id',
            'catalog_group_id',
            'sku_normalized',
            'name_normalized',
            'parent_name',
            'variation_label',
            'is_purchasable',
            'display_price',
            'min_price',
            'max_price',
            'image_url',
            'image_alt',
            'image_version',
            'index_generation',
        ];

        foreach ($columns as $column) {
            if (! self::column_exists('mx_pos_product_index', $column)) {
                return false;
            }
        }

        foreach (['object_id', 'sku_normalized', 'name_normalized', 'catalog_group_id', 'status_stock_group', 'index_generation'] as $index) {
            if (! self::index_exists('mx_pos_product_index', $index)) {
                return false;
            }
        }

        return self::is_1_10_complete();
    }

    private static function table_exists(string $table_name): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . $table_name;
        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        return $found === $table;
    }

    private static function column_exists(string $table_name, string $column): bool
    {
        global $wpdb;

        if (! self::table_exists($table_name)) {
            return false;
        }

        $table = $wpdb->prefix . $table_name;
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                $column
            )
        );

        return $found !== null && $found !== '';
    }

    private static function index_exists(string $table_name, string $index): bool
    {
        global $wpdb;

        if (! self::table_exists($table_name)) {
            return false;
        }

        $table = $wpdb->prefix . $table_name;
        $found = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s
                   AND INDEX_NAME = %s",
                $table,
                $index
            )
        );

        return (int) $found > 0;
    }

    public static function tables_exist(): bool
    {
        global $wpdb;

        $prefix   = $wpdb->prefix;
        $expected = array_keys(Schema::get_tables());

        foreach ($expected as $name) {
            $table = $prefix . $name;
            $found = $wpdb->get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $table)
            );

            if ($found !== $table) {
                return false;
            }
        }

        return true;
    }

    // ── Migration 1.12 — Multi-store: refunds branch/register/employee columns ──

    private static function migrate_to_1_12(): void
    {
        global $wpdb;

        if (self::is_1_12_complete()) {
            return;
        }

        self::ensure_1_12_refund_columns($wpdb);
        self::ensure_1_12_refund_indexes($wpdb);
        self::ensure_1_12_backfill($wpdb);
        self::log_schema_upgraded($wpdb);
    }

    private static function ensure_1_12_refund_columns($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_refunds';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        if (! self::column_exists('mx_pos_refunds', 'branch_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN branch_id BIGINT UNSIGNED DEFAULT NULL AFTER session_id"
            );
        }

        if (! self::column_exists('mx_pos_refunds', 'pos_register_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN pos_register_id BIGINT UNSIGNED DEFAULT NULL AFTER branch_id"
            );
        }

        if (! self::column_exists('mx_pos_refunds', 'pos_employee_id')) {
            $wpdb->query(
                "ALTER TABLE `{$table}` ADD COLUMN pos_employee_id BIGINT UNSIGNED DEFAULT NULL AFTER pos_register_id"
            );
        }
    }

    private static function ensure_1_12_refund_indexes($wpdb): void
    {
        $table = $wpdb->prefix . 'mx_pos_refunds';

        $table_exists = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($table_exists !== $table) {
            return;
        }

        foreach (['branch_id', 'pos_register_id', 'pos_employee_id'] as $index) {
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = %s
                       AND INDEX_NAME = %s",
                    $table,
                    $index
                )
            );

            if ((int) $found === 0) {
                $wpdb->query(
                    "ALTER TABLE `{$table}` ADD KEY `{$index}` (`{$index}`)"
                );
            }
        }
    }

    private static function ensure_1_12_backfill($wpdb): void
    {
        $refunds_table = $wpdb->prefix . 'mx_pos_refunds';
        $sales_table   = $wpdb->prefix . 'mx_pos_sales';

        if (! self::table_exists('mx_pos_refunds') || ! self::table_exists('mx_pos_sales')) {
            return;
        }

        if (
            ! self::column_exists('mx_pos_refunds', 'branch_id')
            || ! self::column_exists('mx_pos_sales', 'branch_id')
        ) {
            return;
        }

        $branch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mx_pos_branches WHERE slug = %s LIMIT 1",
                'main'
            ),
            ARRAY_A
        );

        $register = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}mx_pos_registers WHERE slug = %s LIMIT 1",
                'main'
            ),
            ARRAY_A
        );

        $main_branch_id   = $branch ? (int) $branch['id'] : null;
        $main_register_id = $register ? (int) $register['id'] : null;

        $null_branch = $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$refunds_table}` WHERE branch_id IS NULL"
        );

        if ((int) $null_branch > 0) {
            $wpdb->query(
                "UPDATE `{$refunds_table}` r
                 JOIN `{$sales_table}` s ON r.sale_id = s.id
                 SET r.branch_id = COALESCE(s.branch_id, {$main_branch_id}),
                     r.pos_register_id = COALESCE(s.pos_register_id, {$main_register_id}),
                     r.pos_employee_id = s.pos_employee_id
                 WHERE r.branch_id IS NULL"
            );

            if ($main_branch_id !== null) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE `{$refunds_table}`
                         SET branch_id = %d
                         WHERE branch_id IS NULL",
                        $main_branch_id
                    )
                );
            }

            if ($main_register_id !== null) {
                $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE `{$refunds_table}`
                         SET pos_register_id = %d
                         WHERE pos_register_id IS NULL",
                        $main_register_id
                    )
                );
            }
        }
    }

    private static function needs_1_12_repair(): bool
    {
        return ! self::is_1_12_complete();
    }

    private static function is_1_12_complete(): bool
    {
        return self::is_1_11_complete()
            && self::has_1_12_refund_columns()
            && self::has_1_12_refund_indexes();
    }

    private static function has_1_12_refund_columns(): bool
    {
        return self::column_exists('mx_pos_refunds', 'branch_id')
            && self::column_exists('mx_pos_refunds', 'pos_register_id')
            && self::column_exists('mx_pos_refunds', 'pos_employee_id');
    }

    private static function has_1_12_refund_indexes(): bool
    {
        return self::index_exists('mx_pos_refunds', 'branch_id')
            && self::index_exists('mx_pos_refunds', 'pos_register_id')
            && self::index_exists('mx_pos_refunds', 'pos_employee_id');
    }
}
