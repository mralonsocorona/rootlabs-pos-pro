import { useState, useCallback, useRef, useEffect } from 'react';
import ProductSearchPanel from '../features/register/components/ProductSearchPanel';
import CartPanel from '../features/register/components/CartPanel';
import useCartValidation from '../features/register/hooks/useCartValidation';
import SaleResultModal from '../features/sales/components/SaleResultModal';
import PaymentModal from '../features/payments/components/PaymentModal';
import RefundModal from '../features/refunds/components/RefundModal';
import RefundSearchDrawer from '../features/refunds/components/RefundSearchDrawer';
import { Modal, Drawer, Button, CartOverlay } from '../components/ui';
import ParkCartForm from '../features/parked-carts/components/ParkCartForm';
import ParkedCartDrawer from '../features/parked-carts/components/ParkedCartDrawer';
import type { RestoreParams } from '../features/parked-carts/components/ParkedCartDrawer';
import SalesHistoryPanel from '../features/sales-history/components/SalesHistoryPanel';
import { playBeep } from '../features/register/services/audioFeedback';
import type { Customer } from '../features/customers/types';
import type { DiscountInput, AppliedCoupon } from '../features/discounts/types';
import type { CheckoutResponse } from '../features/payments/types';
import type { RefundResponse } from '../features/refunds/types';
import type { ConnectionStatus } from '../hooks/useNetworkStatus';
import { useCartPersistence } from '../hooks/useCartPersistence';
import type {
  IndexedProduct,
  CartItem,
} from '../features/register/types';

function cartKey(p: IndexedProduct): string {
  return `${p.product_id}-${p.variation_id ?? 0}`;
}

function getUnitPrice(p: IndexedProduct): number {
  const sale = p.sale_price ? parseFloat(p.sale_price) : 0;
  const reg = p.regular_price ? parseFloat(p.regular_price) : 0;
  if (sale > 0) return sale;
  if (reg > 0) return reg;
  return 0;
}

function calculateCartLineDiscount(item: CartItem): number {
  const discount = item.manual_discount as
    | { type?: string; value?: string | number; amount?: string | number }
    | null
    | undefined;

  if (!discount) {
    return 0;
  }

  const lineSubtotal = item.unit_price * item.quantity;
  const rawValue = Number(discount.value ?? discount.amount ?? 0);

  if (!Number.isFinite(rawValue) || rawValue <= 0 || lineSubtotal <= 0) {
    return 0;
  }

  const discountType = String(discount.type ?? '');

  if (discountType === 'percent' || discountType === 'percentage') {
    return Math.min(lineSubtotal, lineSubtotal * (rawValue / 100));
  }

  return Math.min(lineSubtotal, rawValue);
}

function cartSubtotal(items: CartItem[]): number {
  return items.reduce((sum, i) => sum + Math.max(0, i.unit_price * i.quantity - calculateCartLineDiscount(i)), 0);
}

function canAddProduct(product: IndexedProduct): boolean {
  return product.type !== 'variable' && product.stock_status !== 'outofstock';
}

function computeCartFingerprint(
  items: CartItem[],
  customer: Customer | null,
  discount: DiscountInput | null,
  couponCode: string | null,
  parkedCartId: number | null,
): string {
  return JSON.stringify({
    i: items
      .map((i) => ({
        p: i.product_id,
        v: i.variation_id ?? 0,
        q: i.quantity,
        up: i.unit_price,
        d: i.manual_discount ?? null,
        s: i.sku,
      }))
      .sort((a, b) => {
        if (a.p !== b.p) return a.p - b.p;
        return a.v - b.v;
      }),
    c: customer?.id ?? null,
    d: discount,
    cp: couponCode ?? null,
    pc: parkedCartId ?? null,
  });
}

function Register({ connectionStatus }: { connectionStatus: ConnectionStatus }) {
  const [cartItems, setCartItems] = useState<CartItem[]>([]);
  const [selectedCustomer, setSelectedCustomer] = useState<Customer | null>(null);
  const [discount, setDiscount] = useState<DiscountInput | null>(null);
  const [appliedCoupon, setAppliedCoupon] = useState<AppliedCoupon | null>(null);
  const [couponError, setCouponError] = useState<string | null>(null);
  const [showParkForm, setShowParkForm] = useState(false);
  const [showParkedDrawer, setShowParkedDrawer] = useState(false);
  const [showPaymentModal, setShowPaymentModal] = useState(false);
  const [checkoutLoading, setCheckoutLoading] = useState(false);
  const [completedSale, setCompletedSale] = useState<CheckoutResponse | null>(null);
  const [refundSaleData, setRefundSaleData] = useState<CheckoutResponse | null>(null);
  const [currentParkedCartId, setCurrentParkedCartId] = useState<number | null>(
    null,
  );
  const [showHistory, setShowHistory] = useState(false);
  const [showRefundsModal, setShowRefundsModal] = useState(false);
  const [refundFromHistorySaleId, setRefundFromHistorySaleId] = useState<number>(0);
  const [needsRevalidation, setNeedsRevalidation] = useState(false);
  const chargeInProgressRef = useRef(false);
  const lastChargeRequestIdRef = useRef<string | null>(null);
  const lastCartFingerprintRef = useRef<string | null>(null);
  const wasOfflineRef = useRef(false);
  const cartItemsRef = useRef<CartItem[]>([]);
  const { restoreCart, clearPersistedCart, saveCart, saveCartNow } =
    useCartPersistence();

  const {
    cartState,
    validationResult,
    isValidating,
    validateNow,
    resetValidation,
  } = useCartValidation(cartItems, discount, appliedCoupon?.code ?? null);

  useEffect(() => {
    cartItemsRef.current = cartItems;
  }, [cartItems]);

  const buildCartSnapshot = useCallback(
    (items: CartItem[]) => ({
      clientRequestId: lastChargeRequestIdRef.current,
      items,
      customerId: selectedCustomer?.id ?? null,
      discount,
      couponCode: appliedCoupon?.code ?? null,
      parkedCartId: currentParkedCartId,
    }),
    [selectedCustomer, discount, appliedCoupon, currentParkedCartId],
  );

  useEffect(() => {
    const handleParkedCartsEvent = () => {
      setShowParkedDrawer(true);
    };

    const handleShowRefundsEvent = () => {
      setShowRefundsModal(true);
    };

    window.addEventListener('mx-pos:show-parked-carts', handleParkedCartsEvent);
    window.addEventListener('mx-pos:show-refunds', handleShowRefundsEvent);

    return () => {
      window.removeEventListener('mx-pos:show-parked-carts', handleParkedCartsEvent);
      window.removeEventListener('mx-pos:show-refunds', handleShowRefundsEvent);
    };
  }, []);

  const isInitialMount = useRef(true);

  useEffect(() => {
    if (isInitialMount.current) {
      isInitialMount.current = false;
      return;
    }

    if (cartItems.length === 0) {
      clearPersistedCart();
      return;
    }

    saveCart(buildCartSnapshot(cartItems));
  }, [cartItems, buildCartSnapshot, saveCart, clearPersistedCart]);

  useEffect(() => {
    const pending = restoreCart();
    if (!pending) return;

    const ageSeconds = Math.round((Date.now() - pending.timestamp) / 1000);
    const minutes = Math.max(1, Math.round(ageSeconds / 60));
    const confirmed = window.confirm(
      `Se encontró un carrito guardado de hace ${minutes} minuto(s). ¿Deseas restaurarlo?`
    );

    if (confirmed) {
      const restoredItems: CartItem[] = pending.items.map((pi) => ({
        key: `${pi.product_id}-${pi.variation_id ?? 0}`,
        product_id: pi.product_id,
        variation_id: pi.variation_id ?? null,
        quantity: pi.quantity,
        unit_price: 0,
        sku: '',
        name: '',
        type: 'simple' as const,
        stock_status: 'instock' as const,
        stock_quantity: null,
      }));

      setCartItems(restoredItems);

      if (pending.clientRequestId) {
        lastChargeRequestIdRef.current = pending.clientRequestId;
      }

      if (pending.parkedCartId) {
        setCurrentParkedCartId(pending.parkedCartId);
      }

      setNeedsRevalidation(true);
    } else {
      clearPersistedCart();
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    const handleConnectionRestored = () => {
      wasOfflineRef.current = false;
      if (cartItems.length > 0) {
        setNeedsRevalidation(true);
      }
    };

    window.addEventListener('mx-pos:connection-restored', handleConnectionRestored);
    return () => {
      window.removeEventListener('mx-pos:connection-restored', handleConnectionRestored);
    };
  }, [cartItems.length]);

  useEffect(() => {
    if (connectionStatus === 'offline' || connectionStatus === 'degraded') {
      wasOfflineRef.current = true;
    }
  }, [connectionStatus]);

  useEffect(() => {
    if (validationResult?.valid && validationResult.items.length > 0) {
      setCartItems((prev) => {
        let changed = false;
        const next = prev.map((item) => {
          const validated = validationResult.items.find(
            (vi) => vi.product_id === item.product_id && vi.variation_id === item.variation_id
          );

          if (validated) {
            if (!item.name || item.name === '' || item.unit_price === 0) {
              changed = true;
              return {
                ...item,
                name: validated.name,
                sku: validated.sku,
                unit_price: parseFloat(validated.unit_price) || 0,
              };
            }
          }
          return item;
        });

        return changed ? next : prev;
      });
    }
  }, [validationResult]);

  const addToCart = useCallback(
    (product: IndexedProduct) => {
      if (!canAddProduct(product)) {
        return;
      }

      setCartItems((prev) => {
        const key = cartKey(product);
        const existing = prev.find((i) => i.key === key);
        if (existing) {
          return prev.map((i) =>
            i.key === key ? { ...i, quantity: i.quantity + 1 } : i,
          );
        }
        const item: CartItem = {
          key,
          product_id: product.product_id,
          variation_id: product.variation_id,
          sku: product.sku,
          name: product.name,
          type: product.type,
          quantity: 1,
          unit_price: getUnitPrice(product),
          stock_status: product.stock_status,
        };
        return [...prev, item];
      });

      playBeep();
    },
    [],
  );

  const updateQuantity = useCallback(
    (key: string, qty: number) => {
      const safeQty = Math.max(1, qty);

      setCartItems((prev) =>
        prev.map((i) => (i.key === key ? { ...i, quantity: safeQty } : i)),
      );
    },
    [],
  );

  const removeFromCart = useCallback(
    (key: string) => {
      const next = cartItemsRef.current.filter((i) => i.key !== key);

      if (next.length === cartItemsRef.current.length) {
        return;
      }

      cartItemsRef.current = next;
      setCartItems(next);
      saveCartNow(buildCartSnapshot(next));
    },
    [buildCartSnapshot, saveCartNow],
  );

  const handleSelectCustomer = useCallback((customer: Customer) => {
    setSelectedCustomer(customer);
  }, []);

  const handleClearCustomer = useCallback(() => {
    setSelectedCustomer(null);
  }, []);

  const handleUpdateCustomer = useCallback((customer: Customer) => {
    setSelectedCustomer(customer);
  }, []);

  const handleApplyDiscount = useCallback((d: DiscountInput) => {
    setDiscount(d);
  }, []);

  const handleClearDiscount = useCallback(() => {
    setDiscount(null);
  }, []);

  const handleApplyItemDiscount = useCallback((key: string, lineDiscount: DiscountInput) => {
    setCartItems((prev) =>
      prev.map((item) =>
        item.key === key
          ? { ...item, manual_discount: lineDiscount }
          : item,
      ),
    );
  }, []);

  const handleClearItemDiscount = useCallback((key: string) => {
    setCartItems((prev) =>
      prev.map((item) =>
        item.key === key
          ? { ...item, manual_discount: null }
          : item,
      ),
    );
  }, []);


  const handleApplyCoupon = useCallback((coupon: AppliedCoupon) => {
    setAppliedCoupon(coupon);
    setCouponError(null);
  }, []);

  const handleClearCoupon = useCallback(() => {
    setAppliedCoupon(null);
    setCouponError(null);
  }, []);

  const handleParkCart = useCallback(() => {
    setShowParkForm(true);
  }, []);

  const handleParkCreated = useCallback(() => {
    setCartItems([]);
    setSelectedCustomer(null);
    setDiscount(null);
    resetValidation();
    setShowParkForm(false);
    setShowPaymentModal(false);
    lastChargeRequestIdRef.current = null;
    lastCartFingerprintRef.current = null;
  }, [resetValidation]);

  const handleParkCancel = useCallback(() => {
    setShowParkForm(false);
  }, []);

  const handleShowParkedCarts = useCallback(() => {
    setShowParkedDrawer(true);
  }, []);

  const handleCloseParkedCarts = useCallback(() => {
    setShowParkedDrawer(false);
  }, []);

  const handleCharge = useCallback(async () => {
    if (cartItems.length === 0) return;
    if (chargeInProgressRef.current) return;
    if (connectionStatus !== 'online') return;
    if (needsRevalidation) return;

    let currentValidation = validationResult;

    if (cartState !== 'valid' || !currentValidation?.valid) {
      currentValidation = await validateNow();
    }

    if (!currentValidation?.valid) {
      return;
    }

    chargeInProgressRef.current = true;

    const currentFingerprint = computeCartFingerprint(
      cartItems,
      selectedCustomer,
      discount,
      appliedCoupon?.code ?? null,
      currentParkedCartId,
    );

    if (
      !lastChargeRequestIdRef.current ||
      lastCartFingerprintRef.current !== currentFingerprint
    ) {
      lastChargeRequestIdRef.current = crypto.randomUUID();
      lastCartFingerprintRef.current = currentFingerprint;
    }

    setShowPaymentModal(true);
    chargeInProgressRef.current = false;
  }, [
    cartItems,
    cartState,
    validationResult,
    validateNow,
    selectedCustomer,
    discount,
    currentParkedCartId,
    connectionStatus,
    needsRevalidation,
  ]);

  const handleCheckoutComplete = useCallback(
    (result: CheckoutResponse) => {
      setShowPaymentModal(false);
      setCheckoutLoading(false);
      setCompletedSale(result);
      setCartItems([]);
      setSelectedCustomer(null);
      setDiscount(null);
      setAppliedCoupon(null);
      setCouponError(null);
      resetValidation();
      setCurrentParkedCartId(null);
      setNeedsRevalidation(false);
      lastChargeRequestIdRef.current = null;
      lastCartFingerprintRef.current = null;
      clearPersistedCart();
    },
    [resetValidation, clearPersistedCart],
  );

  const handlePaymentModalClose = useCallback(() => {
    if (checkoutLoading) return;
    setShowPaymentModal(false);
  }, [checkoutLoading]);

  const handleRevalidate = useCallback(async () => {
    setNeedsRevalidation(false);
    await validateNow();
  }, [validateNow]);

  const handleCloseSaleResult = useCallback(() => {
    setCompletedSale(null);
  }, []);

  const handleRefundClick = useCallback(() => {
    setRefundSaleData(completedSale);
    setCompletedSale(null);
  }, [completedSale]);

  const handleRefundComplete = useCallback((_result: RefundResponse) => {
    setCompletedSale(null);
    window.dispatchEvent(new CustomEvent('mx-pos:catalog-changed'));
  }, []);

  const handleHistoryRefund = useCallback((saleId: number) => {
    setRefundFromHistorySaleId(saleId);
  }, []);

  const handleHistoryRefundComplete = useCallback((_result: RefundResponse) => {
    void _result;
    window.dispatchEvent(new CustomEvent('mx-pos:catalog-changed'));
  }, []);

  const handleHistoryRefundClose = useCallback(() => {
    setRefundFromHistorySaleId(0);
  }, []);

  const handleRefundClose = useCallback(() => {
    setRefundSaleData(null);
  }, []);

  const handleClearCart = useCallback(() => {
    const confirmed = window.confirm('¿Vaciar el carrito? Se perderán todos los productos.');

    if (!confirmed) {
      return;
    }

    setCartItems([]);
    setSelectedCustomer(null);
    setDiscount(null);
    setAppliedCoupon(null);
    setCouponError(null);
    resetValidation();
    setCurrentParkedCartId(null);
    setShowPaymentModal(false);
    setCheckoutLoading(false);
    setNeedsRevalidation(false);
    lastChargeRequestIdRef.current = null;
    lastCartFingerprintRef.current = null;
    clearPersistedCart();
  }, [resetValidation, clearPersistedCart]);

  const handleRestoreCart = useCallback(
    (params: RestoreParams) => {
      setCartItems(params.items);
      setSelectedCustomer(params.customer);
      setDiscount(params.discount);
      setAppliedCoupon(params.coupon ?? null);
      setCouponError(null);
      setCurrentParkedCartId(params.parkedCartId);
      setShowParkedDrawer(false);
      setShowPaymentModal(false);
      lastChargeRequestIdRef.current = null;
      lastCartFingerprintRef.current = null;
    },
    [],
  );

  const subtotal = cartSubtotal(cartItems);
  const total = validationResult
    ? parseFloat(validationResult.totals.total)
    : subtotal;
  const couponTotal = validationResult
    ? parseFloat(validationResult.totals.coupon_total ?? '0')
    : 0;
  const discountTotal = validationResult
    ? parseFloat(validationResult.totals.discount_total)
    : 0;

  return (
    <div className="mx-register-layout">
      <div className="mx-register-search-column">
        <ProductSearchPanel onAddToCart={addToCart} />
      </div>
      <div className="mx-register-cart-column">
        <div id="mx-cart-overlay-root"></div>
        {needsRevalidation && cartItems.length > 0 && (
          <div className="mx-revalidation-notice">
            <p className="mx-revalidation-notice__text">
              Conexión restablecida. Revalida el carrito antes de cobrar.
            </p>
            <Button
              variant="primary"
              size="sm"
              onClick={handleRevalidate}
              loading={isValidating}
            >
              Revalidar ahora
            </Button>
          </div>
        )}

        <CartPanel
          items={cartItems}
          onUpdateQuantity={updateQuantity}
          onRemoveItem={removeFromCart}
          canApplyDiscount={window.mxPosProSettings?.capabilities?.canApplyDiscount ?? false}
          onApplyItemDiscount={handleApplyItemDiscount}
          onClearItemDiscount={handleClearItemDiscount}
          subtotal={subtotal}
          cartState={cartState}
          isValidating={isValidating}
          validationResult={validationResult}
          onCharge={handleCharge}
          onClearCart={handleClearCart}
          onParkCart={handleParkCart}
          onShowParkedCarts={handleShowParkedCarts}
          selectedCustomer={selectedCustomer}
          onSelectCustomer={handleSelectCustomer}
          onClearCustomer={handleClearCustomer}
          onUpdateCustomer={handleUpdateCustomer}
          discountInput={discount}
          onApplyDiscount={handleApplyDiscount}
          onClearDiscount={handleClearDiscount}
          appliedCoupon={appliedCoupon}
          couponError={
            validationResult?.coupon_error ?? couponError
          }
          onApplyCoupon={handleApplyCoupon}
          onClearCoupon={handleClearCoupon}
          connectionStatus={connectionStatus}
          needsRevalidation={needsRevalidation}
        />
      </div>

      <PaymentModal
        open={showPaymentModal}
        cartItems={cartItems}
        selectedCustomer={selectedCustomer}
        discount={discount}
        appliedCoupon={appliedCoupon}
        parkedCartId={currentParkedCartId}
        clientRequestId={lastChargeRequestIdRef.current ?? crypto.randomUUID()}
        subtotal={subtotal}
        couponTotal={couponTotal}
        discountTotal={discountTotal}
        total={total}
        checkoutLoading={checkoutLoading}
        onSetCheckoutLoading={setCheckoutLoading}
        onCheckoutComplete={handleCheckoutComplete}
        onClose={handlePaymentModalClose}
      />

      <SaleResultModal
        open={completedSale !== null}
        sale={completedSale}
        canRefund={
          window.mxPosProSettings?.capabilities?.canRefund ?? false
        }
        onRefund={handleRefundClick}
        onClose={handleCloseSaleResult}
      />

      <RefundModal
        open={refundSaleData !== null}
        saleId={refundSaleData?.sale.id ?? 0}
        onComplete={handleRefundComplete}
        onClose={handleRefundClose}
      />

      <Modal
        open={showParkForm}
        onClose={handleParkCancel}
        title="Guardar carrito"
      >
        <ParkCartForm
          items={cartItems}
          customerId={selectedCustomer?.id ?? null}
          discount={discount}
          onCreated={handleParkCreated}
          onCancel={handleParkCancel}
        />
      </Modal>

      <Modal
        open={showRefundsModal}
        onClose={() => setShowRefundsModal(false)}
        title="Devoluciones"
        panelClassName="mx-refund-search-modal-panel"
      >
        <RefundSearchDrawer
          showTitle={false}
          onSelectSale={(saleId) => {
            setShowRefundsModal(false);
            handleHistoryRefund(saleId);
          }}
        />
      </Modal>

      {showParkedDrawer && (
        <CartOverlay onClose={handleCloseParkedCarts}>
          <ParkedCartDrawer
            onRestore={handleRestoreCart}
            onClose={handleCloseParkedCarts}
          />
        </CartOverlay>
      )}

      <Drawer
        open={showHistory}
        onClose={() => setShowHistory(false)}
        position="right"
        width="700px"
      >
        <SalesHistoryPanel onClose={() => setShowHistory(false)} />
      </Drawer>

      <RefundModal
        open={refundFromHistorySaleId > 0}
        saleId={refundFromHistorySaleId}
        onComplete={handleHistoryRefundComplete}
        onClose={handleHistoryRefundClose}
      />
    </div>
  );
}

export default Register;
