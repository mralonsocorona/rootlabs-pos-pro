<?php

namespace MXPOSPro\Context;

defined('ABSPATH') || exit;

use MXPOSPro\Auth\POSAuthService;
use MXPOSPro\Cash\CashSessionRepository;
use MXPOSPro\Cash\CashSessionService;
use MXPOSPro\Cash\CashMovementRepository;
use MXPOSPro\Entities\BranchRepository;
use MXPOSPro\Entities\EmployeeRepository;
use MXPOSPro\Entities\RegisterRepository;
use WP_Error;

class POSContextService
{
    private POSAuthService $posAuthService;
    private CashSessionService $sessionService;
    private BranchRepository $branchRepo;
    private RegisterRepository $registerRepo;
    private EmployeeRepository $employeeRepo;

    private static ?array $resolved = null;

    public function __construct(
        ?POSAuthService $posAuthService = null,
        ?CashSessionService $sessionService = null,
        ?BranchRepository $branchRepo = null,
        ?RegisterRepository $registerRepo = null,
        ?EmployeeRepository $employeeRepo = null
    ) {
        $this->posAuthService = $posAuthService ?? new POSAuthService(new EmployeeRepository());
        $this->sessionService = $sessionService ?? new CashSessionService(
            new CashSessionRepository(),
            new CashMovementRepository()
        );
        $this->branchRepo   = $branchRepo ?? new BranchRepository();
        $this->registerRepo = $registerRepo ?? new RegisterRepository();
        $this->employeeRepo = $employeeRepo ?? new EmployeeRepository();
    }

    public function resolve(): array|WP_Error
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $employee = $this->posAuthService->get_current_employee();

        if ($employee !== null && isset($employee['id']) && (int) $employee['id'] > 0) {
            return $this->resolve_for_pos_employee((int) $employee['id']);
        }

        $wpUserId = get_current_user_id();

        if ($wpUserId > 0) {
            return $this->resolve_for_wp_user($wpUserId);
        }

        return new WP_Error(
            'mx_pos_not_authenticated',
            __('No hay sesion activa. Inicia sesion antes de operar.', 'mx-pos-pro'),
            ['status' => 403]
        );
    }

    public function resolve_for_read(): array|WP_Error
    {
        $employee = $this->posAuthService->get_current_employee();

        if ($employee !== null && isset($employee['id']) && (int) $employee['id'] > 0) {
            $employeeId = (int) $employee['id'];
            $sessionResult = $this->sessionService->get_current_session_for_pos_employee($employeeId);

            return $this->build_context(
                $employeeId,
                'pos_employee',
                $sessionResult['session'] ?? null
            );
        }

        $wpUserId = get_current_user_id();

        if ($wpUserId > 0) {
            $sessionResult = $this->sessionService->get_current_session($wpUserId);

            return $this->build_context(
                $wpUserId,
                'wp_user',
                $sessionResult['session'] ?? null
            );
        }

        return new WP_Error(
            'mx_pos_not_authenticated',
            __('No hay sesion activa.', 'mx-pos-pro'),
            ['status' => 403]
        );
    }

    public function resolveRaw(): array|WP_Error
    {
        self::$resolved = null;

        return $this->resolve();
    }

    private function resolve_for_pos_employee(int $employeeId): array|WP_Error
    {
        $sessionResult = $this->sessionService->get_current_session_for_pos_employee($employeeId);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No hay una sesion de caja abierta. Abra una sesion antes de operar.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        return $this->build_context(
            $employeeId,
            'pos_employee',
            $sessionResult['session']
        );
    }

    private function resolve_for_wp_user(int $wpUserId): array|WP_Error
    {
        $sessionResult = $this->sessionService->get_current_session($wpUserId);

        if (! $sessionResult['has_open_session'] || $sessionResult['session'] === null) {
            return new WP_Error(
                'mx_pos_no_open_session',
                __('No hay una sesion de caja abierta. Abra una sesion antes de operar.', 'mx-pos-pro'),
                ['status' => 409]
            );
        }

        return $this->build_context(
            $wpUserId,
            'wp_user',
            $sessionResult['session']
        );
    }

    private function build_context(int $actorId, string $actorType, ?array $session): array|WP_Error
    {
        $branchId      = null;
        $registerId    = null;
        $employeeId    = null;
        $sessionId     = null;
        $branchName    = '';
        $registerName  = '';

        if ($session !== null) {
            $sessionId   = isset($session['id']) ? (int) $session['id'] : null;
            $branchId    = isset($session['branch_id']) ? (int) $session['branch_id'] : null;
            $registerId  = isset($session['pos_register_id']) ? (int) $session['pos_register_id'] : null;
            $employeeId  = isset($session['pos_employee_id']) ? (int) $session['pos_employee_id'] : null;
            $registerName = $session['register_name'] ?? '';
            $branchName   = $session['branch_name'] ?? '';
        }

        if ($registerId !== null && $registerId > 0 && $registerName === '') {
            $register = $this->registerRepo->get_by_id($registerId);
            $registerName = is_array($register) ? ($register['name'] ?? '') : '';
        }

        if ($branchId !== null && $branchId > 0 && $branchName === '') {
            $branch = $this->branchRepo->get_by_id($branchId);
            $branchName = is_array($branch) ? ($branch['name'] ?? '') : '';
        }

        if ($employeeId === null && $actorType === 'pos_employee') {
            $employeeId = $actorId;
        }

        if ($branchId === null || $branchId <= 0) {
            $defaultBranch = $this->branchRepo->get_default();
            if ($defaultBranch !== null) {
                $branchId   = (int) $defaultBranch['id'];
                $branchName = $defaultBranch['name'] ?? '';
            }
        }

        if (($registerId === null || $registerId <= 0) && $branchId !== null && $branchId > 0) {
            $defaultRegister = $this->registerRepo->get_default();
            if ($defaultRegister !== null) {
                $registerId   = (int) $defaultRegister['id'];
                $registerName = $defaultRegister['name'] ?? '';
            }
        }

        $branchId   = $branchId ?? 0;
        $registerId = $registerId ?? 0;
        $employeeId = $employeeId ?? 0;
        $sessionId  = $sessionId ?? 0;

        return self::$resolved = [
            'actor_id'       => $actorId,
            'actor_type'     => $actorType,
            'employee_id'    => $employeeId,
            'branch_id'      => $branchId,
            'branch_name'    => $branchName,
            'register_id'    => $registerId,
            'register_name'  => $registerName,
            'session_id'     => $sessionId,
        ];
    }
}
