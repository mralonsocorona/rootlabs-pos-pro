<?php

namespace MXPOSPro\Cash;

defined('ABSPATH') || exit;

use WP_Error;

class CashSessionService
{
    private CashSessionRepository $repository;
    private CashMovementRepository $movementRepo;

    private const DENOMINATION_CENTS = [
        'bill-1000' => 100000,
        'bill-500'  => 50000,
        'bill-200'  => 20000,
        'bill-100'  => 10000,
        'bill-50'   => 5000,
        'bill-20'   => 2000,
        'coin-20'   => 2000,
        'coin-10'   => 1000,
        'coin-5'    => 500,
        'coin-2'    => 200,
        'coin-1'    => 100,
        'coin-050'  => 50,
    ];

    public function __construct(
        CashSessionRepository $repository,
        CashMovementRepository $movementRepo
    ) {
        $this->repository   = $repository;
        $this->movementRepo = $movementRepo;
    }

    public function get_session_by_id(int $sessionId): ?array
    {
        $session = $this->repository->get_by_id($sessionId);

        if ($session === null) {
            return null;
        }

        return $this->map_session($session);
    }

    public function get_current_session(int $cashier_id): array
    {
        if ($cashier_id <= 0) {
            return [
                'has_open_session' => false,
                'session'          => null,
            ];
        }

        $session = $this->repository->find_open_by_pos_employee($cashier_id);

        if ($session === null) {
            $session = $this->repository->find_open_by_cashier($cashier_id);
        }
if ($session === null) {
            return [
                'has_open_session' => false,
                'session'          => null,
            ];
        }

        return [
            'has_open_session' => true,
            'session'          => $this->map_session($session),
        ];
    }

    public function get_current_session_for_pos_employee(int $pos_employee_id): array
    {
        if ($pos_employee_id <= 0) {
            return [
                'has_open_session' => false,
                'session'          => null,
            ];
        }

        $session = $this->repository->find_open_by_pos_employee($pos_employee_id);

        if ($session === null) {
            return [
                'has_open_session' => false,
                'session'          => null,
            ];
        }

        return [
            'has_open_session' => true,
            'session'          => $this->map_session($session),
        ];
    }

    public function open_session(int $cashier_id, string $opening_amount): array|WP_Error
    {
        if ($cashier_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Invalid user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! is_numeric($opening_amount) || (float) $opening_amount < 0) {
            return new WP_Error(
                'mx_pos_invalid_amount',
                __('Opening amount must be a non-negative number.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $existing = $this->repository->find_open_by_cashier($cashier_id);

        if ($existing !== null) {
            return new WP_Error(
                'mx_pos_session_exists',
                __('You already have an open session.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $session = $this->repository->create(
            $cashier_id,
            $this->formatDecimal((float) $opening_amount),
            ''
        );

        $user = get_userdata($cashier_id);
        $cashier_name = $user ? $user->display_name : '';

        do_action('mx_pos_cash_session_opened', [
            'session_id'     => (int) $session['id'],
            'cashier_id'     => $cashier_id,
            'cashier_name'   => $cashier_name,
            'opening_amount' => $this->formatDecimal((float) $opening_amount),
        ]);

        return $this->map_session($session);
    }

    public function open_session_for_pos_employee(
        int $pos_employee_id,
        int $pos_register_id,
        int $branch_id,
        string $opening_amount,
        ?string $denominations_json = null
    ): array|WP_Error {
        if ($pos_employee_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_employee',
                __('Empleado no válido.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($pos_register_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_register',
                __('Caja no válida.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($branch_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_branch',
                __('Sucursal no válida.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (! is_numeric($opening_amount) || (float) $opening_amount <= 0) {
            return new WP_Error(
                'mx_pos_invalid_amount',
                __('El fondo inicial debe ser mayor a cero.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $registers_table = \MXPOSPro\Entities\RegisterRepository::class;
        $registerRepo = new \MXPOSPro\Entities\RegisterRepository();
        $register     = $registerRepo->get_by_id($pos_register_id);

        if ($register === null) {
            return new WP_Error(
                'mx_pos_register_not_found',
                __('La caja seleccionada no existe.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if (! (int) $register['is_active']) {
            return new WP_Error(
                'mx_pos_register_inactive',
                __('La caja seleccionada no está activa.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ((int) $register['branch_id'] !== $branch_id) {
            return new WP_Error(
                'mx_pos_register_branch_mismatch',
                __('La caja no pertenece a la sucursal indicada.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $branches_table = \MXPOSPro\Entities\BranchRepository::class;
        $branchRepo = new \MXPOSPro\Entities\BranchRepository();
        $branch     = $branchRepo->get_by_id($branch_id);

        if ($branch === null || ! (int) $branch['is_active']) {
            return new WP_Error(
                'mx_pos_branch_inactive',
                __('La sucursal no está activa.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $employeeRepo = new \MXPOSPro\Entities\EmployeeRepository();
        $employee     = $employeeRepo->get_by_id($pos_employee_id);

        if (
            $employee !== null
            && isset($employee['branch_id'])
            && (int) $employee['branch_id'] > 0
            && (int) $employee['branch_id'] !== (int) $branch_id
        ) {
            return new WP_Error(
                'mx_pos_employee_branch_mismatch',
                __('No tienes permitido operar esta sucursal.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        $this->audit_cash_session_open_attempt(
            $pos_employee_id,
            $pos_register_id,
            $branch_id,
            $opening_amount
        );

        $registerOpen = $this->repository->find_open_by_register($pos_register_id);

        if ($registerOpen !== null) {
            $this->audit_cash_session_blocked(
                'cash_session_open_blocked_register_open',
                $pos_employee_id,
                $pos_register_id,
                $branch_id,
                sprintf(
                    'La caja %d ya tiene una sesión abierta (session_id=%d).',
                    $pos_register_id,
                    (int) $registerOpen['id']
                ),
                (int) $registerOpen['id'],
                $registerOpen['status']
            );

            return new WP_Error(
                'mx_pos_register_already_open',
                __('La caja seleccionada ya tiene una sesión abierta.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $employeeOpen = $this->repository->find_open_by_pos_employee($pos_employee_id);

        if ($employeeOpen !== null) {
            $this->audit_cash_session_blocked(
                'cash_session_open_blocked_employee_open',
                $pos_employee_id,
                $pos_register_id,
                $branch_id,
                sprintf(
                    'El empleado %d ya tiene una sesión abierta (session_id=%d).',
                    $pos_employee_id,
                    (int) $employeeOpen['id']
                ),
                (int) $employeeOpen['id'],
                $employeeOpen['status']
            );

            return new WP_Error(
                'mx_pos_employee_has_open_session',
                __('Ya tienes una sesión de caja abierta.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }



        $session = $this->repository->create_for_pos_employee(
            $pos_employee_id,
            $pos_register_id,
            $branch_id,
            $this->formatDecimal((float) $opening_amount),
            $denominations_json
        );

        $this->audit_cash_session_opened_pos(
            $pos_employee_id,
            (int) $session['id'],
            $pos_register_id,
            $branch_id,
            $this->formatDecimal((float) $opening_amount)
        );

        $employeeRepo = new \MXPOSPro\Entities\EmployeeRepository();
        $employee = $employeeRepo->get_by_id($pos_employee_id);
        $cashier_name = $employee ? $employee['display_name'] : '';

        do_action('mx_pos_cash_session_opened', [
            'session_id'         => (int) $session['id'],
            'cashier_id'         => $pos_employee_id,
            'cashier_name'       => $cashier_name,
            'pos_register_id'    => $pos_register_id,
            'branch_id'          => $branch_id,
            'opening_amount'     => $this->formatDecimal((float) $opening_amount),
            'denominations_json' => $denominations_json,
        ]);

        return $session;
    }

    public function close_session(int $sessionId, int $userId, array $denominations, ?string $closeNote): array|WP_Error
    {
        if ($sessionId <= 0 || $userId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_params',
                __('Invalid session or user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $session = $this->repository->get_by_id($sessionId);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_session_not_found',
                __('Session not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($session['status'] !== 'open') {
            return new WP_Error(
                'mx_pos_session_already_closed',
                __('This session is already closed.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $sessionOwnerId = isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0
            ? (int) $session['pos_employee_id']
            : (int) $session['cashier_id'];

        if ($sessionOwnerId !== $userId) {
            return new WP_Error(
                'mx_pos_session_not_owned',
                __('You can only close your own session.', 'mx-pos-pro'),
                ['status' => 403]
            );
        }

        $countedCents = 0;

        foreach ($denominations as $key => $qty) {
            if (! isset(self::DENOMINATION_CENTS[$key])) {
                return new WP_Error(
                    'mx_pos_unknown_denomination',
                    sprintf(__('Unknown denomination: %s', 'mx-pos-pro'), $key),
                    ['status' => 400]
                );
            }

            $intQty = (int) $qty;

            if ($intQty < 0) {
                return new WP_Error(
                    'mx_pos_negative_quantity',
                    __('Denomination quantities must be non-negative.', 'mx-pos-pro'),
                    ['status' => 400]
                );
            }

            $countedCents += $intQty * self::DENOMINATION_CENTS[$key];
        }

        $totals        = $this->movementRepo->totals_by_session($sessionId);
        $openingCents  = $this->toCents($session['opening_amount']);
        $netCents      = $this->toCents($totals['net']);
        $expectedCents = $openingCents + $netCents;
        $diffCents     = $countedCents - $expectedCents;

        $closeNoteTrimmed = $closeNote !== null ? trim($closeNote) : '';

        if ($diffCents !== 0 && $closeNoteTrimmed === '') {
            return new WP_Error(
                'mx_pos_close_note_required',
                __('A note explaining the cash difference is required.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (mb_strlen($closeNoteTrimmed) > 500) {
            return new WP_Error(
                'mx_pos_close_note_too_long',
                __('Close note must not exceed 500 characters.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $data = [
            'status'             => 'closed',
            'closing_expected'   => $this->centsToDecimal($expectedCents),
            'closing_counted'    => $this->centsToDecimal($countedCents),
            'difference'         => $this->centsToDecimal($diffCents),
            'closed_by'          => $userId,
            'close_note'         => $closeNoteTrimmed === '' ? null : $closeNoteTrimmed,
            'denominations_json' => json_encode($denominations),
            'closed_at'          => current_time('mysql'),
        ];

        $closed = $this->repository->close($sessionId, $data);

        if ($closed === null) {
            return new WP_Error(
                'mx_pos_session_already_closed',
                __('The session was already closed.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $this->log_audit($userId, $sessionId, $expectedCents, $countedCents, $diffCents, $closeNoteTrimmed);

        $diffDecimal = $this->centsToDecimal($diffCents);

        $employeeRepo = new \MXPOSPro\Entities\EmployeeRepository();
        $employee = $employeeRepo->get_by_id($sessionOwnerId);
        $cashier_name = $employee ? $employee['display_name'] : '';
        if ($cashier_name === '') {
            $user = get_userdata($sessionOwnerId);
            $cashier_name = $user ? $user->display_name : '';
        }

        $saleRepo = new \MXPOSPro\Sales\SaleRepository();
        $saleTotals = $saleRepo->get_totals_by_session($sessionId);
        $classifiedMovements = $this->movementRepo->classified_totals_by_session($sessionId);

        do_action('mx_pos_cash_session_closed', [
            'session_id'         => $sessionId,
            'cashier_id'         => $userId,
            'cashier_name'       => $cashier_name,
            'expected_amount'    => $this->centsToDecimal($expectedCents),
            'counted_amount'     => $this->centsToDecimal($countedCents),
            'difference'         => $diffDecimal,
            'denominations_json' => wp_json_encode($denominations),
            'opening_amount'     => $session['opening_amount'],
            'sales_cash'         => $classifiedMovements['sales_cash_in_total'] ?? '0.0000',
            'sales_card'         => $saleTotals['card_sales'] ?? '0.0000',
            'pos_register_id'    => $session['pos_register_id'] ?? 0,
        ]);

        if ($diffCents !== 0) {
            do_action('mx_pos_cash_difference_detected', [
                'session_id' => $sessionId,
                'cashier_id' => $userId,
                'difference' => $diffDecimal,
                'close_note' => $closeNoteTrimmed,
            ]);
        }

        $mappedTotals = [
            'cash_in'  => $totals['cash_in'],
            'cash_out' => $totals['cash_out'],
            'net'      => $totals['net'],
        ];

        return [
            'session' => $this->map_closed_session($closed),
            'totals'  => $mappedTotals,
        ];
    }

    public function close_session_remote(int $sessionId, int $adminUserId, string $remoteReason): array|WP_Error
    {
        if ($sessionId <= 0 || $adminUserId <= 0) {
            return new WP_Error(
                'mx_pos_invalid_params',
                __('Invalid session or user.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $remoteReasonTrimmed = trim($remoteReason);

        if ($remoteReasonTrimmed === '') {
            return new WP_Error(
                'mx_pos_remote_close_reason_required',
                __('El motivo del cierre remoto es obligatorio.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (mb_strlen($remoteReasonTrimmed) > 500) {
            return new WP_Error(
                'mx_pos_remote_close_reason_too_long',
                __('El motivo del cierre remoto no debe exceder 500 caracteres.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $session = $this->repository->get_by_id($sessionId);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_session_not_found',
                __('Session not found.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($session['status'] !== 'open') {
            return new WP_Error(
                'mx_pos_session_already_closed',
                __('This session is already closed or voided.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $data = [
            'status'             => 'closed',
            'closing_expected'   => null,
            'closing_counted'    => null,
            'difference'         => null,
            'closed_by'          => $adminUserId,
            'close_note'         => $remoteReasonTrimmed,
            'denominations_json' => null,
            'closed_at'          => current_time('mysql'),
        ];

        $closed = $this->repository->close($sessionId, $data);

        if ($closed === null) {
            return new WP_Error(
                'mx_pos_session_already_closed',
                __('The session was already closed.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $this->log_audit_remote($adminUserId, $sessionId, $session, $remoteReasonTrimmed);

        $originalCashierId = isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0
            ? (int) $session['pos_employee_id']
            : (int) ($session['cashier_id'] ?? 0);

        do_action('mx_pos_cash_session_closed_remote', [
            'session_id'          => $sessionId,
            'admin_user_id'       => $adminUserId,
            'original_cashier_id' => $originalCashierId,
            'remote_reason'       => $remoteReasonTrimmed,
        ]);

        return [
            'session' => $this->map_closed_session($closed),
            'totals'  => [
                'cash_in'  => '0.0000',
                'cash_out' => '0.0000',
                'net'      => '0.0000',
            ],
        ];
    }

    private function log_audit_remote(
        int $adminUserId,
        int $sessionId,
        array $session,
        string $remoteReason
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $originalCashierId = isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0
            ? (int) $session['pos_employee_id']
            : (int) ($session['cashier_id'] ?? 0);
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $wpdb->insert(
            $table,
            [
                'actor_id'     => $adminUserId,
                'action'       => 'session_closed_remote',
                'entity_type'  => 'cash_session',
                'entity_id'    => $sessionId,
                'ip_address'   => $ipAddress,
                'context_data' => wp_json_encode([
                    'remote_reason'       => $remoteReason,
                    'closed_remotely'     => true,
                    'admin_user_id'       => $adminUserId,
                    'original_cashier_id' => $originalCashierId,
                    'branch_id'           => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
                    'pos_register_id'     => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
                ]),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private function map_session(array $session): array
    {
        return [
            'id'              => (int) $session['id'],
            'opened_at'       => $session['opened_at'],
            'opened_by'       => (int) $session['cashier_id'],
            'opening_amount'  => $this->formatDecimal((float) $session['opening_amount']),
            'expected_amount' => isset($session['closing_expected']) ? $this->formatDecimal((float) $session['closing_expected']) : null,
            'counted_amount'  => isset($session['closing_counted']) ? $this->formatDecimal((float) $session['closing_counted']) : null,
            'difference'      => isset($session['difference']) ? $this->formatDecimal((float) $session['difference']) : null,
            'close_note'      => $session['close_note'] ?? null,
            'closed_at'       => $session['closed_at'] ?? null,
            'closed_by'       => isset($session['closed_by']) ? (int) $session['closed_by'] : null,
            'status'          => $session['status'],
            'pos_employee_id' => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
            'cashier_id'      => isset($session['cashier_id']) ? (int) $session['cashier_id'] : null,
            'branch_id'       => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
            'pos_register_id' => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
            'register_name'   => $session['register_name'] ?? null,
            'employee_name'   => $session['employee_name'] ?? null,
            'branch_name'     => $session['branch_name'] ?? null,
        ];
    }

    private function map_closed_session(array $session): array
    {
        return [
            'id'              => (int) $session['id'],
            'opened_at'       => $session['opened_at'],
            'closed_at'       => $session['closed_at'] ?? null,
            'opening_amount'  => $this->formatDecimal((float) $session['opening_amount']),
            'expected_amount' => isset($session['closing_expected']) ? $this->formatDecimal((float) $session['closing_expected']) : null,
            'counted_amount'  => isset($session['closing_counted']) ? $this->formatDecimal((float) $session['closing_counted']) : null,
            'difference'      => isset($session['difference']) ? $this->formatDecimal((float) $session['difference']) : null,
            'close_note'      => $session['close_note'] ?? null,
            'closed_by'       => isset($session['closed_by']) ? (int) $session['closed_by'] : null,
            'status'          => $session['status'],
        ];
    }

    private function toCents(string $amount): int
    {
        $isNegative = $amount !== '' && $amount[0] === '-';
        $normalized = ltrim(ltrim($amount, '+'), '-');
        $parts      = explode('.', $normalized);
        $pesos      = (int) $parts[0];
        $cents      = isset($parts[1])
            ? (int) substr(str_pad($parts[1], 4, '0'), 0, 2)
            : 0;

        $total = ($pesos * 100) + $cents;

        return $isNegative ? -$total : $total;
    }

    private function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs  = abs($cents);
        $pesos = intdiv($abs, 100);
        $fraccion = $abs % 100;

        return sprintf('%s%d.%02d0000', $sign, $pesos, $fraccion);
    }

    private function log_audit(
        int $userId,
        int $sessionId,
        int $expectedCents,
        int $countedCents,
        int $diffCents,
        string $closeNote
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        $wpdb->insert(
            $table,
            [
                'actor_id'     => $userId,
                'action'       => 'session_closed',
                'entity_type'  => 'cash_session',
                'entity_id'    => $sessionId,
                'ip_address'   => $ipAddress,
                'context_data' => wp_json_encode([
                    'expected_cents' => $expectedCents,
                    'counted_cents'  => $countedCents,
                    'diff_cents'     => $diffCents,
                    'close_note'     => $closeNote,
                ]),
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private function formatDecimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    public function void_session(int $session_id, int $admin_user_id, string $reason): array|WP_Error
    {
        if ($session_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_session',
                __('ID de sesión no válido.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if ($admin_user_id <= 0) {
            return new WP_Error(
                'mx_pos_invalid_user',
                __('Usuario no válido.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $reason = trim($reason);

        if ($reason === '') {
            return new WP_Error(
                'mx_pos_void_reason_required',
                __('El motivo de anulación es obligatorio.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        if (mb_strlen($reason) > 500) {
            return new WP_Error(
                'mx_pos_void_reason_too_long',
                __('El motivo de anulación no debe exceder 500 caracteres.', 'mx-pos-pro'),
                ['status' => 400]
            );
        }

        $session = $this->repository->get_by_id($session_id);

        if ($session === null) {
            return new WP_Error(
                'mx_pos_session_not_found',
                __('La sesión no existe.', 'mx-pos-pro'),
                ['status' => 404]
            );
        }

        if ($session['status'] !== 'open') {
            $this->audit_cash_session_void_failed(
                $session_id,
                $admin_user_id,
                $session,
                'Session status is not open'
            );

            return new WP_Error(
                'mx_pos_session_not_open',
                __('Solo se pueden anular sesiones abiertas.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $this->audit_cash_session_void_attempt(
            $session_id,
            $admin_user_id,
            $session,
            $reason
        );

        $voided = $this->repository->void($session_id, $admin_user_id, $reason);

        if ($voided === null) {
            $this->audit_cash_session_void_failed(
                $session_id,
                $admin_user_id,
                $session,
                'Repository void returned null'
            );

            return new WP_Error(
                'mx_pos_session_void_failed',
                __('No se pudo anular la sesión. Puede que ya haya sido cerrada o anulada.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $this->audit_cash_session_voided_admin(
            $admin_user_id,
            $voided,
            $reason
        );

        return $voided;
    }

    public function resolve_active_session_for_sale(
        int $legacy_user_id = 0,
        ?int $pos_employee_id = null
    ): array|WP_Error {
        $session = null;

        if ($pos_employee_id !== null && $pos_employee_id > 0) {
            $session = $this->repository->find_open_by_pos_employee($pos_employee_id);
        } elseif ($legacy_user_id > 0) {
            $session = $this->repository->find_open_by_cashier($legacy_user_id);
        }

        if ($session === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No hay una sesión de caja abierta. Abra una sesión antes de operar.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        if ($session['status'] !== 'open') {
            return new WP_Error(
                'mx_pos_session_not_open',
                __('La sesión de caja no está abierta.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        $has_employee = isset($session['pos_employee_id']) && (int) $session['pos_employee_id'] > 0;
        $has_register = isset($session['pos_register_id']) && (int) $session['pos_register_id'] > 0;
        $has_branch   = isset($session['branch_id']) && (int) $session['branch_id'] > 0;

        if ($has_employee && $has_register && $has_branch) {
            return [
                'id'              => (int) $session['id'],
                'pos_employee_id' => (int) $session['pos_employee_id'],
                'pos_register_id' => (int) $session['pos_register_id'],
                'branch_id'       => (int) $session['branch_id'],
                'opening_amount'  => $session['opening_amount'],
                'status'          => $session['status'],
                'opened_at'       => $session['opened_at'],
            ];
        }

        return [
            'id'             => (int) $session['id'],
            'cashier_id'     => (int) ($session['cashier_id'] ?? 0),
            'opening_amount' => $session['opening_amount'],
            'status'         => $session['status'],
            'opened_at'      => $session['opened_at'],
        ];
    }

    private function audit_cash_session_open_attempt(
        int $pos_employee_id,
        int $pos_register_id,
        int $branch_id,
        string $opening_amount
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => null,
                    'branch_id'        => $branch_id,
                    'pos_register_id'  => $pos_register_id,
                    'pos_employee_id'  => $pos_employee_id,
                    'action'           => 'cash_session_open_attempt',
                    'entity_type'      => 'cash_session',
                    'entity_id'        => null,
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'pos_employee_id'  => $pos_employee_id,
                        'pos_register_id'  => $pos_register_id,
                        'branch_id'        => $branch_id,
                        'opening_amount'   => $opening_amount,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for cash_session_open_attempt employee=%d register=%d: %s',
                    $pos_employee_id,
                    $pos_register_id,
                    $e->getMessage()
                )
            );
        }
    }

    private function audit_cash_session_blocked(
        string $action,
        int $pos_employee_id,
        int $pos_register_id,
        int $branch_id,
        string $reason,
        ?int $existing_session_id = null,
        ?string $existing_session_status = null
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => null,
                    'branch_id'        => $branch_id,
                    'pos_register_id'  => $pos_register_id,
                    'pos_employee_id'  => $pos_employee_id,
                    'action'           => $action,
                    'entity_type'      => 'cash_session',
                    'entity_id'        => $existing_session_id,
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'pos_employee_id'          => $pos_employee_id,
                        'pos_register_id'          => $pos_register_id,
                        'branch_id'                => $branch_id,
                        'reason'                   => $reason,
                        'existing_session_id'      => $existing_session_id,
                        'existing_session_status'  => $existing_session_status,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for %s employee=%d register=%d: %s',
                    $action,
                    $pos_employee_id,
                    $pos_register_id,
                    $e->getMessage()
                )
            );
        }
    }

    private function audit_cash_session_opened_pos(
        int $pos_employee_id,
        int $sessionId,
        int $pos_register_id,
        int $branch_id,
        string $opening_amount
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => null,
                    'branch_id'        => $branch_id,
                    'pos_register_id'  => $pos_register_id,
                    'pos_employee_id'  => $pos_employee_id,
                    'action'           => 'cash_session_opened_pos',
                    'entity_type'      => 'cash_session',
                    'entity_id'        => $sessionId,
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'opening_amount'  => $opening_amount,
                        'pos_register_id' => $pos_register_id,
                        'branch_id'       => $branch_id,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for cash_session_opened_pos session=%d: %s',
                    $sessionId,
                    $e->getMessage()
                )
            );
        }
    }

    private function audit_cash_session_void_attempt(
        int $session_id,
        int $admin_user_id,
        array $session,
        string $reason
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => $admin_user_id,
                    'branch_id'        => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
                    'pos_register_id'  => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
                    'pos_employee_id'  => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
                    'action'           => 'cash_session_void_attempt_admin',
                    'entity_type'      => 'cash_session',
                    'entity_id'        => $session_id,
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'session_id'       => $session_id,
                        'admin_user_id'    => $admin_user_id,
                        'previous_status'   => $session['status'],
                        'void_reason'      => $reason,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for cash_session_void_attempt_admin session=%d: %s',
                    $session_id,
                    $e->getMessage()
                )
            );
        }
    }

    private function audit_cash_session_voided_admin(
        int $admin_user_id,
        array $session,
        string $reason
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => $admin_user_id,
                    'branch_id'        => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
                    'pos_register_id'  => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
                    'pos_employee_id'  => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
                    'action'           => 'cash_session_voided_admin',
                    'entity_type'      => 'cash_session',
                    'entity_id'        => (int) $session['id'],
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'session_id'       => (int) $session['id'],
                        'voided_by'        => $admin_user_id,
                        'void_reason'      => $reason,
                        'previous_status'   => 'open',
                        'pos_employee_id'  => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
                        'pos_register_id'  => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
                        'branch_id'        => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for cash_session_voided_admin session=%d: %s',
                    (int) $session['id'],
                    $e->getMessage()
                )
            );
        }
    }

    private function audit_cash_session_void_failed(
        int $session_id,
        int $admin_user_id,
        array $session,
        string $error_reason
    ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'mx_pos_audit_logs';

        $found = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        );

        if ($found !== $table) {
            return;
        }

        try {
            $ip         = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
                : '';
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
                : '';

            $wpdb->insert(
                $table,
                [
                    'actor_id'         => $admin_user_id,
                    'branch_id'        => isset($session['branch_id']) ? (int) $session['branch_id'] : null,
                    'pos_register_id'  => isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null,
                    'pos_employee_id'  => isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null,
                    'action'           => 'cash_session_void_failed',
                    'entity_type'      => 'cash_session',
                    'entity_id'        => $session_id,
                    'ip_address'       => $ip,
                    'user_agent'       => $user_agent,
                    'context_data'     => wp_json_encode([
                        'session_id'       => $session_id,
                        'admin_user_id'    => $admin_user_id,
                        'previous_status'   => $session['status'],
                        'error_reason'     => $error_reason,
                    ]),
                    'created_at'       => current_time('mysql'),
                ],
                ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
        } catch (\Exception $e) {
            error_log(
                sprintf(
                    '[MX POS Pro] Audit write failed for cash_session_void_failed session=%d: %s',
                    $session_id,
                    $e->getMessage()
                )
            );
        }
    }
}
