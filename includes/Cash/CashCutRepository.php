<?php

namespace MXPOSPro\Cash;

defined('ABSPATH') || exit;

use WP_Error;

class CashCutRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;

        $this->table = $wpdb->prefix . 'mx_pos_cash_cuts';
    }

    public function table_name(): string
    {
        return $this->table;
    }

    public function create(array $data): array|WP_Error
    {
        global $wpdb;

        $insertData = [
            'session_id'      => (int) $data['session_id'],
            'branch_id'       => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'pos_register_id' => isset($data['pos_register_id']) && (int) $data['pos_register_id'] > 0 ? (int) $data['pos_register_id'] : null,
            'pos_employee_id' => isset($data['pos_employee_id']) && (int) $data['pos_employee_id'] > 0 ? (int) $data['pos_employee_id'] : null,
            'cut_type'     => $data['cut_type'],
            'sequence'     => (int) ($data['sequence'] ?? 1),
            'summary_json' => $data['summary_json'],
            'generated_by' => (int) $data['generated_by'],
            'generated_at' => $data['generated_at'] ?? current_time('mysql'),
            'is_final'     => (int) ($data['is_final'] ?? 0),
        ];

        $formats = ['%d', '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%d'];

        $result = $wpdb->insert($this->table, $insertData, $formats);

        if ($result === false) {
            return new WP_Error(
                'mx_pos_cut_insert_failed',
                __('Failed to insert cash cut record.', 'mx-pos-pro'),
                ['status' => 500]
            );
        }

        return $this->get_by_id((int) $wpdb->insert_id);
    }

    public function get_by_id(int $cut_id): ?array
    {
        global $wpdb;

        if ($cut_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
                $cut_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function find_final_by_session(int $session_id): ?array
    {
        global $wpdb;

        if ($session_id <= 0) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE session_id = %d AND cut_type = 'Z' AND is_final = 1 LIMIT 1",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    public function list_by_session(int $session_id): array
    {
        global $wpdb;

        if ($session_id <= 0) {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE session_id = %d ORDER BY generated_at DESC, id DESC",
                $session_id
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function list_all(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $where    = '1=1';
        $whereArgs = [];

        if (! empty($filters['session_id']) && is_numeric($filters['session_id'])) {
            $where       .= ' AND session_id = %d';
            $whereArgs[] = (int) $filters['session_id'];
        }

        if (! empty($filters['cut_type']) && in_array($filters['cut_type'], ['X', 'Z'], true)) {
            $where       .= ' AND cut_type = %s';
            $whereArgs[] = $filters['cut_type'];
        }

        if (! empty($filters['date_from'])) {
            $where       .= ' AND generated_at >= %s';
            $whereArgs[] = $filters['date_from'] . ' 00:00:00';
        }

        if (! empty($filters['date_to'])) {
            $where       .= ' AND generated_at <= %s';
            $whereArgs[] = $filters['date_to'] . ' 23:59:59';
        }

        $countQuery = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
        if (! empty($whereArgs)) {
            $countQuery = $wpdb->prepare($countQuery, ...$whereArgs);
        }
        $total = (int) $wpdb->get_var($countQuery);

        $dataQuery = "SELECT id, session_id, cut_type, sequence, generated_by, generated_at, is_final
                      FROM {$this->table}
                      WHERE {$where}
                      ORDER BY generated_at DESC, id DESC
                      LIMIT %d OFFSET %d";
        $dataArgs = array_merge($whereArgs, [$perPage, $offset]);
        $dataQuery = $wpdb->prepare($dataQuery, ...$dataArgs);

        $rows = $wpdb->get_results($dataQuery, ARRAY_A);

        $cuts = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $cuts[] = [
                    'id'           => (int) $row['id'],
                    'session_id'   => (int) $row['session_id'],
                    'cut_type'     => $row['cut_type'],
                    'sequence'     => (int) $row['sequence'],
                    'generated_by' => $this->get_user_name((int) $row['generated_by']),
                    'generated_at' => $row['generated_at'],
                    'is_final'     => (bool) $row['is_final'],
                ];
            }
        }

        return [
            'cuts'     => $cuts,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    private function get_user_name(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $user = get_userdata($userId);

        if (! $user instanceof \WP_User) {
            return '';
        }

        return $user->display_name;
    }
}
