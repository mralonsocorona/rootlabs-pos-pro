<?php

namespace MXPOSPro\Reports;

defined('ABSPATH') || exit;

class DashboardDataService
{
    private string $salesTable;
    private string $sessionsTable;
    private string $refundsTable;
    private string $movementsTable;
    private string $cutsTable;
    private string $orderPaymentsTable;
    private string $paymentMethodsTable;
    private string $auditTable;
    private string $branchesTable;
    private string $registersTable;
    private string $employeesTable;

    public function __construct()
    {
        global $wpdb;

        $this->salesTable          = $wpdb->prefix . 'mx_pos_sales';
        $this->sessionsTable       = $wpdb->prefix . 'mx_pos_sessions';
        $this->refundsTable        = $wpdb->prefix . 'mx_pos_refunds';
        $this->movementsTable      = $wpdb->prefix . 'mx_pos_cash_movements';
        $this->cutsTable           = $wpdb->prefix . 'mx_pos_cash_cuts';
        $this->orderPaymentsTable  = $wpdb->prefix . 'mx_pos_order_payments';
        $this->paymentMethodsTable = $wpdb->prefix . 'mx_pos_payment_methods';
        $this->auditTable          = $wpdb->prefix . 'mx_pos_audit_logs';
        $this->branchesTable       = $wpdb->prefix . 'mx_pos_branches';
        $this->registersTable      = $wpdb->prefix . 'mx_pos_registers';
        $this->employeesTable      = $wpdb->prefix . 'mx_pos_employees';
    }

    private function build_where(array &$whereClauses, array &$whereArgs, string $column, ?int $value): void
    {
        if ($value !== null && $value > 0) {
            $whereClauses[] = "{$column} = %d";
            $whereArgs[]    = $value;
        }
    }

    private function build_date_range(array &$whereClauses, array &$whereArgs, string $column, string $dateFrom, string $dateTo): void
    {
        $whereClauses[] = "{$column} >= %s";
        $whereArgs[]    = $dateFrom . ' 00:00:00';
        $whereClauses[] = "{$column} <= %s";
        $whereArgs[]    = $dateTo . ' 23:59:59';
    }

    public function get_kpi_data(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId): array
    {
        global $wpdb;

        $where   = [];
        $args    = [];
        $salesAlias = 's';

        $this->build_date_range($where, $args, "{$salesAlias}.created_at", $dateFrom, $dateTo);
        $where[] = "{$salesAlias}.status IN ('completed', 'processing')";
        $this->build_where($where, $args, "{$salesAlias}.branch_id", $branchId);
        $this->build_where($where, $args, "{$salesAlias}.pos_register_id", $registerId);
        $this->build_where($where, $args, "{$salesAlias}.pos_employee_id", $employeeId);

        $whereStr = implode(' AND ', $where);

        $salesSql = $wpdb->prepare(
            "SELECT COALESCE(SUM(total), 0) AS gross_sales, COUNT(*) AS ticket_count
             FROM {$this->salesTable} {$salesAlias}
             WHERE {$whereStr}",
            ...$args
        );

        $row = $wpdb->get_row($salesSql, ARRAY_A);
        $grossSales  = (float) ($row['gross_sales'] ?? 0);
        $ticketCount = (int) ($row['ticket_count'] ?? 0);
        $avgTicket   = $ticketCount > 0 ? $grossSales / $ticketCount : 0;

        $refundWhere   = [];
        $refundArgs    = [];
        $this->build_date_range($refundWhere, $refundArgs, 'created_at', $dateFrom, $dateTo);
        $this->build_where($refundWhere, $refundArgs, 'branch_id', $branchId);
        $this->build_where($refundWhere, $refundArgs, 'pos_register_id', $registerId);
        $this->build_where($refundWhere, $refundArgs, 'pos_employee_id', $employeeId);

        $refundWhereStr = implode(' AND ', $refundWhere);
        $refundSql = $wpdb->prepare(
            "SELECT COALESCE(SUM(refund_amount), 0) AS refund_total, COUNT(*) AS refund_count
             FROM {$this->refundsTable}
             WHERE {$refundWhereStr}",
            ...$refundArgs
        );
        $refundRow = $wpdb->get_row($refundSql, ARRAY_A);

        $sessionWhere   = [];
        $sessionArgs    = [];
        $this->build_date_range($sessionWhere, $sessionArgs, 'opened_at', $dateFrom, $dateTo);
        $this->build_where($sessionWhere, $sessionArgs, 'branch_id', $branchId);
        $this->build_where($sessionWhere, $sessionArgs, 'pos_register_id', $registerId);

        $openWhere = $sessionWhere;
        $openWhere[] = 'status = %s';
        $openArgs = array_merge($sessionArgs, ['open']);
        $openCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->sessionsTable} WHERE " . implode(' AND ', $openWhere),
                ...$openArgs
            )
        );

        $closedWhere = $sessionWhere;
        $closedWhere[] = 'status = %s';
        $closedArgs = array_merge($sessionArgs, ['closed']);
        $closedSql = $wpdb->prepare(
            "SELECT COUNT(*) AS closed_count, COALESCE(SUM(closing_expected), 0) AS expected_cash
             FROM {$this->sessionsTable}
             WHERE " . implode(' AND ', $closedWhere),
            ...$closedArgs
        );
        $closedRow = $wpdb->get_row($closedSql, ARRAY_A);
        $closedCount   = (int) ($closedRow['closed_count'] ?? 0);
        $expectedCash  = (float) ($closedRow['expected_cash'] ?? 0);

        $diffWhere = $sessionWhere;
        $diffWhere[] = 'status = %s';
        $diffWhere[] = 'difference IS NOT NULL';
        $diffWhere[] = 'difference != 0.0000';
        $diffArgs = array_merge($sessionArgs, ['closed']);
        $diffCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->sessionsTable} WHERE " . implode(' AND ', $diffWhere),
                ...$diffArgs
            )
        );

        $remoteWhere   = [];
        $remoteArgs    = [];
        $this->build_date_range($remoteWhere, $remoteArgs, 'created_at', $dateFrom, $dateTo);
        $remoteWhere[] = 'action = %s';
        $remoteArgs[]  = 'session_closed_remote';
        $remoteCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->auditTable} WHERE " . implode(' AND ', $remoteWhere),
                ...$remoteArgs
            )
        );

        return [
            'gross_sales'       => number_format($grossSales, 2, '.', ''),
            'ticket_count'      => $ticketCount,
            'avg_ticket'        => number_format($avgTicket, 2, '.', ''),
            'refund_total'      => number_format((float) ($refundRow['refund_total'] ?? 0), 2, '.', ''),
            'refund_count'      => (int) ($refundRow['refund_count'] ?? 0),
            'expected_cash'     => number_format($expectedCash, 2, '.', ''),
            'open_sessions'     => $openCount,
            'closed_sessions'   => $closedCount,
            'difference_closures' => $diffCount,
            'remote_closures'   => $remoteCount,
        ];
    }

    public function get_sales_by_employee(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId): array
    {
        global $wpdb;

        $where = [];
        $args  = [];
        $this->build_date_range($where, $args, 's.created_at', $dateFrom, $dateTo);
        $where[] = "s.status IN ('completed', 'processing')";
        $this->build_where($where, $args, 's.branch_id', $branchId);
        $this->build_where($where, $args, 's.pos_register_id', $registerId);
        if ($employeeId !== null && $employeeId > 0) {
            $where[] = 's.pos_employee_id = %d';
            $args[]  = $employeeId;
        }

        $whereStr = implode(' AND ', $where);

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.pos_employee_id,
                        COALESCE(e.display_name, '—') AS employee_name,
                        COUNT(*) AS sale_count,
                        COALESCE(SUM(s.total), 0) AS total_sales
                 FROM {$this->salesTable} s
                 LEFT JOIN {$this->employeesTable} e ON s.pos_employee_id = e.id
                 WHERE {$whereStr}
                 GROUP BY s.pos_employee_id, e.display_name
                 ORDER BY total_sales DESC",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    public function get_sales_by_payment_method(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId): array
    {
        global $wpdb;

        $where = [];
        $args  = [];
        $this->build_date_range($where, $args, 's.created_at', $dateFrom, $dateTo);
        $where[] = "s.status IN ('completed', 'processing')";
        $this->build_where($where, $args, 's.branch_id', $branchId);
        $this->build_where($where, $args, 's.pos_register_id', $registerId);
        $this->build_where($where, $args, 's.pos_employee_id', $employeeId);

        $whereStr = implode(' AND ', $where);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT op.payment_method_id,
                        pm.name AS method_name,
                        COUNT(DISTINCT op.sale_id) AS sale_count,
                        COALESCE(SUM(op.amount), 0) AS total_amount
                 FROM {$this->orderPaymentsTable} op
                 JOIN {$this->paymentMethodsTable} pm ON op.payment_method_id = pm.id
                 JOIN {$this->salesTable} s ON op.sale_id = s.id
                 WHERE {$whereStr}
                 GROUP BY op.payment_method_id, pm.name
                 ORDER BY total_amount DESC",
                ...$args
            ),
            ARRAY_A
        ) ?: [];

        $grandTotal = 0;
        foreach ($rows as $row) {
            $grandTotal += (float) $row['total_amount'];
        }

        $result = [];
        foreach ($rows as $row) {
            $amount = (float) $row['total_amount'];
            $result[] = [
                'payment_method_id' => (int) $row['payment_method_id'],
                'method_name'       => $row['method_name'],
                'sale_count'        => (int) $row['sale_count'],
                'total_amount'      => number_format($amount, 2, '.', ''),
                'percentage'        => $grandTotal > 0 ? number_format(($amount / $grandTotal) * 100, 1, '.', '') : '0.0',
            ];
        }

        return $result;
    }

    public function get_discounts_coupons_summary(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId): array
    {
        global $wpdb;

        $where = [];
        $args  = [];
        $this->build_date_range($where, $args, 'created_at', $dateFrom, $dateTo);
        $where[] = "status IN ('completed', 'processing')";
        $this->build_where($where, $args, 'branch_id', $branchId);
        $this->build_where($where, $args, 'pos_register_id', $registerId);
        $this->build_where($where, $args, 'pos_employee_id', $employeeId);

        $whereStr = implode(' AND ', $where);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payment_summary FROM {$this->salesTable} WHERE {$whereStr}",
                ...$args
            ),
            ARRAY_A
        ) ?: [];

        $discountTotal    = 0.0;
        $discountCount    = 0;
        $couponTotal      = 0.0;
        $couponCount      = 0;
        $couponsByCode    = [];

        foreach ($rows as $row) {
            $summary = null;
            if (! empty($row['payment_summary'])) {
                $decoded = json_decode($row['payment_summary'], true);
                if (is_array($decoded)) {
                    $summary = $decoded;
                }
            }

            if ($summary === null) {
                continue;
            }

            $dTotal = (float) ($summary['discount_total'] ?? 0);
            if ($dTotal > 0) {
                $discountTotal += $dTotal;
                $discountCount++;
            }

            $cData = $summary['coupon'] ?? null;
            if (is_array($cData) && ! empty($cData['code'])) {
                $cAmount = (float) ($cData['discount_total'] ?? 0);
                $cCode   = $cData['code'];
                $couponTotal += $cAmount;
                $couponCount++;
                if (! isset($couponsByCode[$cCode])) {
                    $couponsByCode[$cCode] = ['code' => $cCode, 'count' => 0, 'total' => 0.0];
                }
                $couponsByCode[$cCode]['count']++;
                $couponsByCode[$cCode]['total'] += $cAmount;
            }
        }

        return [
            'discount_total'  => number_format($discountTotal, 2, '.', ''),
            'discount_count'  => $discountCount,
            'coupon_total'    => number_format($couponTotal, 2, '.', ''),
            'coupon_count'    => $couponCount,
            'coupons_by_code' => array_values($couponsByCode),
        ];
    }

    public function get_cash_movements_paginated(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId, int $page, int $perPage): array
    {
        global $wpdb;

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $args  = [];
        $this->build_date_range($where, $args, 'm.created_at', $dateFrom, $dateTo);
        $this->build_where($where, $args, 'm.branch_id', $branchId);
        $this->build_where($where, $args, 'm.pos_register_id', $registerId);
        $this->build_where($where, $args, 'm.pos_employee_id', $employeeId);

        $whereStr = implode(' AND ', $where);

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->movementsTable} m WHERE {$whereStr}",
                ...$args
            )
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.id, m.created_at, m.movement_type, m.amount, m.reason,
                        COALESCE(e.display_name, '—') AS employee_name,
                        COALESCE(r.name, '—') AS register_name,
                        COALESCE(b.name, '—') AS branch_name
                 FROM {$this->movementsTable} m
                 LEFT JOIN {$this->employeesTable} e ON m.pos_employee_id = e.id
                 LEFT JOIN {$this->registersTable} r ON m.pos_register_id = r.id
                 LEFT JOIN {$this->branchesTable} b ON m.branch_id = b.id
                 WHERE {$whereStr}
                 ORDER BY m.created_at DESC
                 LIMIT %d OFFSET %d",
                ...array_merge($args, [$perPage, $offset])
            ),
            ARRAY_A
        ) ?: [];

        return [
            'items'    => $items,
            'total'    => $count,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function get_closures(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId): array
    {
        global $wpdb;

        $where = [];
        $args  = [];
        $alias = 'se';
        $this->build_date_range($where, $args, "{$alias}.opened_at", $dateFrom, $dateTo);
        $where[] = "{$alias}.status = 'closed'";
        $this->build_where($where, $args, "{$alias}.branch_id", $branchId);
        $this->build_where($where, $args, "{$alias}.pos_register_id", $registerId);
        $this->build_where($where, $args, "{$alias}.pos_employee_id", $employeeId);

        $whereStr = implode(' AND ', $where);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT se.id AS session_id,
                        se.opening_amount,
                        se.closing_expected,
                        se.closing_counted,
                        se.difference,
                        se.close_note,
                        se.closed_by,
                        se.closed_at,
                        COALESCE(r.name, '—') AS register_name,
                        COALESCE(e.display_name, '—') AS employee_name,
                        COALESCE(b.name, '—') AS branch_name
                 FROM {$this->sessionsTable} se
                 LEFT JOIN {$this->registersTable} r ON se.pos_register_id = r.id
                 LEFT JOIN {$this->employeesTable} e ON se.pos_employee_id = e.id
                 LEFT JOIN {$this->branchesTable} b ON se.branch_id = b.id
                 WHERE {$whereStr}
                 ORDER BY se.closed_at DESC",
                ...$args
            ),
            ARRAY_A
        ) ?: [];

        $sessionIds = [];
        foreach ($rows as $row) {
            $sessionIds[] = (int) $row['session_id'];
        }

        $remoteMap = [];
        if (! empty($sessionIds)) {
            $placeholders = implode(',', array_fill(0, count($sessionIds), '%d'));
            $sqlArgs = array_merge(['session_closed_remote'], $sessionIds);
            $remoteRows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT entity_id, actor_id, context_data
                     FROM {$this->auditTable}
                     WHERE action = %s
                       AND entity_type = 'cash_session'
                       AND entity_id IN ({$placeholders})",
                    ...$sqlArgs
                ),
                ARRAY_A
            ) ?: [];

            foreach ($remoteRows as $rRow) {
                $sid = (int) $rRow['entity_id'];
                $remoteMap[$sid] = [
                    'admin_id'   => (int) $rRow['actor_id'],
                    'admin_name' => $this->resolve_user_name((int) $rRow['actor_id']),
                ];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $sid    = (int) $row['session_id'];
            $closedBy = (int) $row['closed_by'];
            $isRemote   = isset($remoteMap[$sid]);
            $typeLabel  = $isRemote ? 'Remoto' : 'Normal';

            $result[] = [
                'session_id'       => $sid,
                'opening_amount'   => $row['opening_amount'],
                'closing_expected' => $row['closing_expected'],
                'closing_counted'  => $row['closing_counted'],
                'difference'       => $row['difference'],
                'close_note'       => $row['close_note'],
                'closed_by'        => $closedBy,
                'closed_at'        => $row['closed_at'],
                'register_name'    => $row['register_name'],
                'employee_name'    => $row['employee_name'],
                'branch_name'      => $row['branch_name'],
                'is_remote'        => $isRemote,
                'type_label'       => $typeLabel,
                'admin_name'       => $isRemote ? ($remoteMap[$sid]['admin_name'] ?? '—') : null,
            ];
        }

        return $result;
    }

    public function get_refunds_paginated(string $dateFrom, string $dateTo, ?int $branchId, ?int $registerId, ?int $employeeId, int $page, int $perPage): array
    {
        global $wpdb;

        $perPage = max(1, min(100, $perPage));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        $where = [];
        $args  = [];
        $this->build_date_range($where, $args, 'rf.created_at', $dateFrom, $dateTo);
        $where[] = "rf.refund_type IN ('partial', 'total', 'full')";
        $this->build_where($where, $args, 'rf.branch_id', $branchId);
        $this->build_where($where, $args, 'rf.pos_register_id', $registerId);
        $this->build_where($where, $args, 'rf.pos_employee_id', $employeeId);

        $whereStr = implode(' AND ', $where);

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->refundsTable} rf WHERE {$whereStr}",
                ...$args
            )
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT rf.id, rf.created_at, rf.refund_type, rf.refund_amount, rf.refund_method, rf.reason, rf.sale_id,
                        COALESCE(u.display_name, '—') AS cashier_name,
                        COALESCE(b.name, '—') AS branch_name,
                        COALESCE(r.name, '—') AS register_name
                 FROM {$this->refundsTable} rf
                 LEFT JOIN {$wpdb->users} u ON rf.cashier_id = u.ID
                 LEFT JOIN {$this->branchesTable} b ON rf.branch_id = b.id
                 LEFT JOIN {$this->registersTable} r ON rf.pos_register_id = r.id
                 WHERE {$whereStr}
                 ORDER BY rf.created_at DESC
                 LIMIT %d OFFSET %d",
                ...array_merge($args, [$perPage, $offset])
            ),
            ARRAY_A
        ) ?: [];

        return [
            'items'    => $items,
            'total'    => $count,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    public function get_filter_options(): array
    {
        global $wpdb;

        $branches = $wpdb->get_results(
            "SELECT id, name FROM {$this->branchesTable} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        ) ?: [];

        $registers = $wpdb->get_results(
            "SELECT id, name FROM {$this->registersTable} WHERE is_active = 1 ORDER BY name ASC",
            ARRAY_A
        ) ?: [];

        $employees = $wpdb->get_results(
            "SELECT id, display_name FROM {$this->employeesTable} WHERE is_active = 1 AND deleted_at IS NULL ORDER BY display_name ASC",
            ARRAY_A
        ) ?: [];

        return [
            'branches'  => $branches,
            'registers' => $registers,
            'employees' => $employees,
        ];
    }

    private function resolve_user_name(int $userId): string
    {
        if ($userId <= 0) {
            return '—';
        }

        $user = get_userdata($userId);
        if (! $user instanceof \WP_User) {
            return '—';
        }

        return $user->display_name;
    }
}
