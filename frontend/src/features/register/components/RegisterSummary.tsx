import { MoneyDisplay, Button } from '../../../components/ui';
import type { ConnectionStatus } from '../../../hooks/useNetworkStatus';
import ValidationResultPanel from './ValidationResultPanel';
import DiscountPanel from '../../discounts/components/DiscountPanel';
import PromotionPanel from '../../discounts/components/PromotionPanel';
import type { CartValidationResponse, CartState } from '../types';
import type { DiscountInput } from '../../discounts/types';
import type { AppliedCoupon } from '../../discounts/types';

interface RegisterSummaryProps {
  subtotal: number;
  cartState: CartState;
  isValidating: boolean;
  validationResult: CartValidationResponse | null;
  onCharge: () => void;
  onClearCart: () => void;
  onParkCart: () => void;
  onShowParkedCarts: () => void;
  discountInput: DiscountInput | null;
  onApplyDiscount: (discount: DiscountInput) => void;
  onClearDiscount: () => void;
  appliedCoupon: AppliedCoupon | null;
  couponError: string | null;
  onApplyCoupon: (coupon: AppliedCoupon) => void;
  onClearCoupon: () => void;
  connectionStatus?: ConnectionStatus;
  needsRevalidation?: boolean;
}

function RegisterSummary({
  subtotal,
  cartState,
  isValidating,
  validationResult,
  onCharge,
  onClearCart,
  discountInput,
  onApplyDiscount,
  onClearDiscount,
  appliedCoupon,
  couponError,
  onApplyCoupon,
  onClearCoupon,
  connectionStatus = 'online',
  needsRevalidation = false,
}: RegisterSummaryProps) {
  const couponTotal = validationResult
    ? parseFloat(validationResult.totals.coupon_total ?? '0')
    : 0;
  const discountTotal = validationResult
    ? parseFloat(validationResult.totals.discount_total)
    : 0;
  const total = validationResult
    ? parseFloat(validationResult.totals.total)
    : subtotal;

  const isCharging = isValidating;
  const isBlocked = connectionStatus !== 'online' || needsRevalidation;
  const canCharge =
    subtotal > 0 &&
    !isCharging &&
    !isBlocked &&
    cartState !== 'invalid';

  let chargeLabel = 'Cobrar';
  if (cartState === 'validating') {
    chargeLabel = 'Validando…';
  } else if (needsRevalidation) {
    chargeLabel = 'Revalida el carrito para cobrar';
  } else if (connectionStatus === 'offline') {
    chargeLabel = 'Sin conexión — Cobro bloqueado';
  } else if (connectionStatus === 'degraded') {
    chargeLabel = 'Servidor no disponible — Cobro bloqueado';
  } else if (connectionStatus === 'checking') {
    chargeLabel = 'Verificando conexión…';
  }

  const handleClearCartClick = () => {
    const confirmed = window.confirm(
      '¿Vaciar el carrito? Se perderán todos los productos.',
    );
    if (confirmed) {
      onClearCart();
    }
  };

  return (
    <div className="mx-register-summary">

      {couponTotal > 0 && validationResult && (
        <div className="mx-register-summary__row mx-register-summary__row--discount">
          <span className="mx-register-summary__label">
            Cupón
            {validationResult.coupon?.code
              ? ` (${validationResult.coupon.code})`
              : ''}
          </span>
          <MoneyDisplay amount={-couponTotal} size="md" />
        </div>
      )}

      {discountTotal > 0 && validationResult && (
        <div className="mx-register-summary__row mx-register-summary__row--discount">
          <span className="mx-register-summary__label">Descuento</span>
          <MoneyDisplay amount={-discountTotal} size="md" />
        </div>
      )}

      <div className="mx-register-summary__row mx-register-summary__row--total">
        <span className="mx-register-summary__label">Total</span>
        <MoneyDisplay amount={total} size="lg" emphasized />
      </div>

      <PromotionPanel
        appliedCoupon={appliedCoupon}
        couponError={couponError}
        onApplyCoupon={onApplyCoupon}
        onClearCoupon={onClearCoupon}
      >
        <DiscountPanel
          discountInput={discountInput}
          validatedDiscount={validationResult?.discount ?? null}
          canApplyDiscount={
            window.mxPosProSettings?.capabilities?.canApplyDiscount ?? false
          }
          onApply={onApplyDiscount}
          onClear={onClearDiscount}
        />
      </PromotionPanel>

      <Button
        variant="primary"
        size="lg"
        disabled={!canCharge}
        loading={isCharging}
        onClick={onCharge}
        className="mx-register-summary__button"
      >
        {chargeLabel}
      </Button>

      {cartState === 'invalid' && validationResult && (
        <div className="mx-register-summary__error" role="alert">
          {validationResult.items.some((item) => !item.valid)
            ? 'Revisa los productos marcados antes de cobrar.'
            : validationResult.errors.length > 0
              ? validationResult.errors.join('. ')
              : 'El carrito no es válido. Revisa los productos.'}
        </div>
      )}

      <div className="mx-register-summary__clear-section">
        <Button
          variant="danger"
          size="sm"
          onClick={handleClearCartClick}
          disabled={cartState === 'validating'}
          className="mx-register-summary__clear-button"
        >
          Vaciar carrito
        </Button>
      </div>

      {(() => {
        const hasErrors =
          validationResult !== null &&
          (
            cartState === 'invalid' ||
            cartState === 'error' ||
            validationResult.errors.length > 0 ||
            validationResult.items.some((item) => !item.valid)
          );

        if (!hasErrors) return null;

        return <ValidationResultPanel result={validationResult} />;
      })()}
    </div>
  );
}

export default RegisterSummary;
