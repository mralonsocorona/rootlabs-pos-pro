import { Button } from '../../../components/ui';
import CartItemRow from './CartItemRow';
import RegisterSummary from './RegisterSummary';
import CustomerSelector from '../../customers/components/CustomerSelector';
import type { Customer } from '../../customers/types';
import type { DiscountInput, AppliedCoupon } from '../../discounts/types';
import type { CartItem, CartValidationResponse, CartState } from '../types';
import type { ConnectionStatus } from '../../../hooks/useNetworkStatus';

interface CartPanelProps {
  items: CartItem[];
  onUpdateQuantity: (key: string, qty: number) => void;
  onRemoveItem: (key: string) => void;
  canApplyDiscount: boolean;
  onApplyItemDiscount: (key: string, discount: DiscountInput) => void;
  onClearItemDiscount: (key: string) => void;
  subtotal: number;
  cartState: CartState;
  isValidating: boolean;
  validationResult: CartValidationResponse | null;
  onCharge: () => void;
  onClearCart: () => void;
  onParkCart: () => void;
  onShowParkedCarts: () => void;
  selectedCustomer: Customer | null;
  onSelectCustomer: (customer: Customer) => void;
  onClearCustomer: () => void;
  onUpdateCustomer?: (customer: Customer) => void;
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

function CartPanel({
  items,
  onUpdateQuantity,
  onRemoveItem,
  canApplyDiscount,
  onApplyItemDiscount,
  onClearItemDiscount,
  subtotal,
  cartState,
  isValidating,
  validationResult,
  onCharge,
  onClearCart,
  onParkCart,
  onShowParkedCarts,
  selectedCustomer,
  onSelectCustomer,
  onClearCustomer,
  onUpdateCustomer,
  discountInput,
  onApplyDiscount,
  onClearDiscount,
  appliedCoupon,
  couponError,
  onApplyCoupon,
  onClearCoupon,
  connectionStatus,
  needsRevalidation,
}: CartPanelProps) {
  const totalItems = items.reduce((sum, i) => sum + i.quantity, 0);

  return (
    <div className="mx-register-cart">
      <div className="mx-register-cart__header">
        <div className="mx-register-cart__header-main">
          <h3 className="mx-register-cart__title">Carrito</h3>
        {totalItems > 0 && (
          <span className="mx-register-cart__badge">{totalItems}</span>
        )}
        </div>
        <div className="mx-register-cart__header-actions">
          <Button
            variant="secondary"
            size="sm"
            onClick={onShowParkedCarts}
            disabled={cartState === 'validating'}
            className="mx-register-cart__header-action mx-register-cart__header-action--saved"
          >
            Carritos guardados
          </Button>
          <Button
            variant="secondary"
            size="sm"
            onClick={onParkCart}
            disabled={cartState === 'validating'}
            className="mx-register-cart__header-action mx-register-cart__header-action--save"
          >
            Guardar carrito
          </Button>
        </div>
      </div>

      <div className="mx-register-cart__customer-area">
        <CustomerSelector
          customer={selectedCustomer}
          onSelect={onSelectCustomer}
          onClear={onClearCustomer}
          onUpdated={onUpdateCustomer}
        />
      </div>

      {items.length === 0 ? (
        <div className="mx-register-cart__empty">
          <p className="mx-register-cart__empty-title">El carrito está vacío</p>
          <p className="mx-register-cart__empty-hint">
            Agrega productos desde el catálogo.
          </p>
        </div>
      ) : (
        <div className="mx-register-cart__list">
          {items.map((item) => (
            <CartItemRow
              key={item.key}
              item={item}
              onUpdateQuantity={onUpdateQuantity}
              onRemoveItem={onRemoveItem}
              canApplyDiscount={canApplyDiscount}
              onApplyItemDiscount={onApplyItemDiscount}
              onClearItemDiscount={onClearItemDiscount}
            />
          ))}
        </div>
      )}

      <RegisterSummary
        subtotal={subtotal}
        cartState={cartState}
        isValidating={isValidating}
        validationResult={validationResult}
        onCharge={onCharge}
        onClearCart={onClearCart}
        onParkCart={onParkCart}
        onShowParkedCarts={onShowParkedCarts}
        discountInput={discountInput}
        onApplyDiscount={onApplyDiscount}
        onClearDiscount={onClearDiscount}
        appliedCoupon={appliedCoupon}
        couponError={couponError}
        onApplyCoupon={onApplyCoupon}
        onClearCoupon={onClearCoupon}
        connectionStatus={connectionStatus}
        needsRevalidation={needsRevalidation}
      />
    </div>
  );
}

export default CartPanel;
