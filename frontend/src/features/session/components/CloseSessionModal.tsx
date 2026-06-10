import { useState, useCallback, useEffect, useMemo } from 'react';
import { Modal, Button, MoneyDisplay, Input } from '../../../components/ui';
import { closeSession } from '../services/cashSessionApi';
import type { CloseSessionTotals, CloseSessionResponse } from '../types';

interface Denomination {
  key: string;
  label: string;
  value: number;
}

const BILL_DENOMINATIONS: Denomination[] = [
  { key: 'bill-1000', label: 'Billete $1000', value: 1000 },
  { key: 'bill-500', label: 'Billete $500', value: 500 },
  { key: 'bill-200', label: 'Billete $200', value: 200 },
  { key: 'bill-100', label: 'Billete $100', value: 100 },
  { key: 'bill-50', label: 'Billete $50', value: 50 },
  { key: 'bill-20', label: 'Billete $20', value: 20 },
];

const COIN_DENOMINATIONS: Denomination[] = [
  { key: 'coin-20', label: 'Moneda $20', value: 20 },
  { key: 'coin-10', label: 'Moneda $10', value: 10 },
  { key: 'coin-5', label: 'Moneda $5', value: 5 },
  { key: 'coin-2', label: 'Moneda $2', value: 2 },
  { key: 'coin-1', label: 'Moneda $1', value: 1 },
  { key: 'coin-050', label: 'Moneda $0.50', value: 0.5 },
];

const ALL_DENOMINATIONS = [...BILL_DENOMINATIONS, ...COIN_DENOMINATIONS];

function parseQuantity(value: string | undefined): number {
  if (!value) return 0;
  const parsed = parseInt(value, 10);
  if (Number.isNaN(parsed) || parsed < 0) return 0;

  return parsed;
}

function parseMoney(value: string | number | undefined | null): number {
  if (value === null || value === undefined) return 0;
  const parsed = typeof value === 'number' ? value : parseFloat(value);
  return Number.isNaN(parsed) ? 0 : parsed;
}

const CLOSE_SESSION_DRAFT_PREFIX = 'mx-pos:close-session-draft';

interface CloseSessionDraft {
  sessionId: number;
  quantities: Record<string, string>;
  closeNote: string;
  updatedAt: number;
}

function getCloseSessionDraftKey(sessionId: number): string {
  return `${CLOSE_SESSION_DRAFT_PREFIX}:${sessionId}`;
}

function sanitizeDraftQuantities(value: unknown): Record<string, string> {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }

  return Object.entries(value as Record<string, unknown>).reduce<Record<string, string>>(
    (acc, [key, rawValue]) => {
      if (!key) return acc;

      if (typeof rawValue === 'string') {
        acc[key] =
          rawValue === '' ? '' : String(Math.max(0, parseInt(rawValue, 10) || 0));
        return acc;
      }

      if (typeof rawValue === 'number' && Number.isFinite(rawValue)) {
        acc[key] = String(Math.max(0, Math.trunc(rawValue)));
      }

      return acc;
    },
    {},
  );
}

function readCloseSessionDraft(
  sessionId: number,
): Pick<CloseSessionDraft, 'quantities' | 'closeNote'> | null {
  if (typeof window === 'undefined' || sessionId <= 0) {
    return null;
  }

  const key = getCloseSessionDraftKey(sessionId);

  try {
    const raw = window.localStorage.getItem(key);
    if (!raw) return null;

    const parsed = JSON.parse(raw) as Partial<CloseSessionDraft>;

    if (!parsed || parsed.sessionId !== sessionId) {
      return null;
    }

    return {
      quantities: sanitizeDraftQuantities(parsed.quantities),
      closeNote: typeof parsed.closeNote === 'string' ? parsed.closeNote : '',
    };
  } catch {
    try {
      window.localStorage.removeItem(key);
    } catch {}

    return null;
  }
}

function writeCloseSessionDraft(
  sessionId: number,
  quantities: Record<string, string>,
  closeNote: string,
): void {
  if (typeof window === 'undefined' || sessionId <= 0) {
    return;
  }

  const hasDraft =
    closeNote.trim() !== '' ||
    Object.values(quantities).some((value) => value !== '' && parseInt(value, 10) > 0);

  if (!hasDraft) {
    clearCloseSessionDraft(sessionId);
    return;
  }

  try {
    window.localStorage.setItem(
      getCloseSessionDraftKey(sessionId),
      JSON.stringify({
        sessionId,
        quantities,
        closeNote,
        updatedAt: Date.now(),
      } satisfies CloseSessionDraft),
    );
  } catch {}
}

function clearCloseSessionDraft(sessionId: number): void {
  if (typeof window === 'undefined' || sessionId <= 0) {
    return;
  }

  try {
    window.localStorage.removeItem(getCloseSessionDraftKey(sessionId));
  } catch {}
}

interface CloseSessionModalProps {
  open: boolean;
  sessionId: number;
  openingAmount: number;
  movementTotals: CloseSessionTotals;
  onClosed: (result: CloseSessionResponse) => void;
  onCancel: () => void;
}

type Step = 'form' | 'confirm';

function CloseSessionModal({
  open,
  sessionId,
  openingAmount,
  movementTotals,
  onClosed,
  onCancel,
}: CloseSessionModalProps) {
  const [quantities, setQuantities] = useState<Record<string, string>>(
    () => readCloseSessionDraft(sessionId)?.quantities ?? {},
  );
  const [closeNote, setCloseNote] = useState(
    () => readCloseSessionDraft(sessionId)?.closeNote ?? '',
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [step, setStep] = useState<Step>('form');

  useEffect(() => {
    if (!open) return;

    const draft = readCloseSessionDraft(sessionId);
    if (!draft) return;

    setQuantities(draft.quantities);
    setCloseNote(draft.closeNote);
    setError(null);
    setStep('form');
  }, [open, sessionId]);

  useEffect(() => {
    if (!open) return;

    writeCloseSessionDraft(sessionId, quantities, closeNote);
  }, [open, sessionId, quantities, closeNote]);

  const resetForm = useCallback(() => {
    setQuantities({});
    setCloseNote('');
    setError(null);
    setStep('form');
    setSubmitting(false);
  }, []);

  const handleClose = useCallback(() => {
    if (!submitting) {
      setError(null);
      setStep('form');
      onCancel();
    }
  }, [submitting, onCancel]);

  const cashIn = useMemo(() => parseFloat(movementTotals.cash_in), [movementTotals.cash_in]);
  const cashOut = useMemo(() => parseFloat(movementTotals.cash_out), [movementTotals.cash_out]);
  const net = useMemo(() => parseFloat(movementTotals.net), [movementTotals.net]);
  const salesCashIn = useMemo(
    () => parseMoney(movementTotals.sales_cash_in_total),
    [movementTotals.sales_cash_in_total],
  );
  const salesChangeOut = useMemo(
    () => parseMoney(movementTotals.sales_change_out_total),
    [movementTotals.sales_change_out_total],
  );
void salesChangeOut;
  const cardSales = useMemo(
    () => parseMoney(movementTotals.card_sales),
    [movementTotals.card_sales],
  );
  const refundTotal = useMemo(
    () => parseMoney(movementTotals.refund_total),
    [movementTotals.refund_total],
  );
  const manualCashIn = useMemo(
    () => parseMoney(movementTotals.manual_cash_in_total),
    [movementTotals.manual_cash_in_total],
  );
  const manualCashOut = useMemo(
    () => parseMoney(movementTotals.manual_cash_out_total),
    [movementTotals.manual_cash_out_total],
  );
  const expected = useMemo(() => openingAmount + net, [openingAmount, net]);

  const counted = useMemo(() =>
    ALL_DENOMINATIONS.reduce(
      (total, d) => total + parseQuantity(quantities[d.key]) * d.value,
      0,
    ),
    [quantities],
  );

  const difference = useMemo(() => counted - expected, [counted, expected]);
  const hasDifference = useMemo(() => Math.abs(difference) >= 0.001, [difference]);
  const canSubmit = useMemo(() => {
    if (submitting) return false;
    if (hasDifference && closeNote.trim() === '') return false;
    return true;
  }, [submitting, hasDifference, closeNote]);

  const formatDiffLabel = useMemo(() => {
    if (Math.abs(difference) < 0.001) return '$0.00';
    const sign = difference > 0 ? '+' : '';
    return `${sign}$${difference.toFixed(2)}`;
  }, [difference]);

  const diffClass = useMemo(() => {
    if (Math.abs(difference) < 0.001) return 'mx-close-session-difference--zero';
    return difference > 0
      ? 'mx-close-session-difference--positive'
      : 'mx-close-session-difference--negative';
  }, [difference]);

  const updateQuantity = useCallback((key: string, value: string) => {
    const numericValue = value === '' ? '' : String(Math.max(0, parseInt(value, 10) || 0));
    setQuantities((prev) => ({ ...prev, [key]: numericValue }));
  }, []);

  const renderDenominationRow = useCallback(
    (denomination: Denomination) => {
      const qty = parseQuantity(quantities[denomination.key]);
      const subtotal = qty * denomination.value;
      const inputId = `mx-close-denomination-${denomination.key}`;

      return (
        <div className="mx-session-denomination-row" key={denomination.key}>
          <label className="mx-session-denomination-row__label" htmlFor={inputId}>
            {denomination.label}
          </label>
          <input
            id={inputId}
            type="number"
            className="mx-session-denomination-row__input"
            min={0}
            step={1}
            inputMode="numeric"
            value={quantities[denomination.key] ?? ''}
            onChange={(e) =>
              updateQuantity(denomination.key, (e.target as HTMLInputElement).value)
            }
            disabled={submitting}
            aria-label={`Cantidad para ${denomination.label}`}
          />
          <div className="mx-session-denomination-row__subtotal">
            <MoneyDisplay amount={subtotal} size="sm" />
          </div>
        </div>
      );
    },
    [submitting, quantities, updateQuantity],
  );

  const handleGoToConfirm = useCallback(() => {
    setError(null);
    setStep('confirm');
  }, []);

  const handleBack = useCallback(() => {
    setError(null);
    setStep('form');
  }, []);

  const handleSubmit = useCallback(async () => {
    if (submitting) return;

    setSubmitting(true);
    setError(null);

    try {
      const result = await closeSession(sessionId, {
        denominations: Object.fromEntries(
          ALL_DENOMINATIONS.map((d) => [d.key, parseQuantity(quantities[d.key])]),
        ),
        close_note: closeNote.trim() || '',
      });

      clearCloseSessionDraft(sessionId);
      resetForm();
      onClosed(result);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo cerrar la caja');
    } finally {
      setSubmitting(false);
    }
  }, [submitting, sessionId, quantities, closeNote, resetForm, onClosed]);

  return (
    <Modal
      open={open}
      onClose={handleClose}
      title={step === 'form' ? 'Realizar cierre' : 'Confirmar cierre'}
      panelClassName="mx-close-session-modal-panel"
    >
      <div className="mx-close-session-modal">
        {step === 'form' && (
          <>
            <div className="mx-close-session-modal__columns">
              <div className="mx-close-session-modal__summary">
                <h3 className="mx-close-session-modal__section-title">Resumen</h3>

                <div className="mx-close-session-summary">
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Apertura</span>
                    <MoneyDisplay amount={openingAmount} size="md" />
                  </div>
                  <div className="mx-close-session-summary__group">
                    <p className="mx-close-session-summary__group-title">
                      Desglose de cobros
                    </p>
                    <div className="mx-close-session-summary__row">
                      <span className="mx-close-session-summary__label">Ventas en efectivo</span>
                      <MoneyDisplay amount={salesCashIn} size="md" />
                    </div>
                    <div className="mx-close-session-summary__row">
                      <span className="mx-close-session-summary__label">Ventas con tarjeta</span>
                      <MoneyDisplay amount={cardSales} size="md" />
                    </div>
                  </div>
                  <div className="mx-close-session-summary__group">
                    <p className="mx-close-session-summary__group-title">
                      Movimientos manuales
                    </p>
                    <div className="mx-close-session-summary__row">
                      <span className="mx-close-session-summary__label">Ingresos manuales</span>
                      <MoneyDisplay amount={manualCashIn} size="md" />
                    </div>
                    <div className="mx-close-session-summary__row">
                      <span className="mx-close-session-summary__label">Salidas manuales</span>
                      <MoneyDisplay amount={manualCashOut} size="md" />
                    </div>
                  </div>
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Entradas de caja totales</span>
                    <MoneyDisplay amount={cashIn} size="md" />
                  </div>
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Salidas de caja totales</span>
                    <MoneyDisplay amount={cashOut} size="md" />
                  </div>
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Devoluciones</span>
                    <MoneyDisplay amount={refundTotal} size="md" />
                  </div>
                  <div className="mx-close-session-summary__row mx-close-session-summary__row--net">
                    <span className="mx-close-session-summary__label">Efectivo esperado</span>
                    <MoneyDisplay amount={expected} size="lg" emphasized />
                  </div>
                  <div className="mx-close-session-summary__divider" />
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Efectivo contado</span>
                    <MoneyDisplay amount={counted} size="md" />
                  </div>
                  <div className="mx-close-session-summary__row">
                    <span className="mx-close-session-summary__label">Diferencia</span>
                    <span className={`mx-close-session-summary__difference ${diffClass}`}>
                      {formatDiffLabel}
                    </span>
                  </div>
                </div>

                <div className="mx-close-session-modal__note">
                  <Input
                    id="mx-close-note"
                    type="text"
                    label={
                      hasDifference
                        ? 'Nota de cierre (obligatorio si hay diferencia)'
                        : 'Nota de cierre (opcional)'
                    }
                    value={closeNote}
                    placeholder={
                      hasDifference
                        ? 'Describe el motivo de la diferencia'
                        : 'Nota de cierre'
                    }
                    disabled={submitting}
                    errorText={
                      hasDifference && closeNote.trim() === ''
                        ? 'La nota es obligatoria cuando hay diferencia'
                        : undefined
                    }
                    onChange={(e) => setCloseNote((e.target as HTMLInputElement).value)}
                  />
                </div>
              </div>

              <div className="mx-close-session-modal__denominations">
                <div className="mx-session-denomination">
                  <div className="mx-session-denomination__section">
                    <h2 className="mx-session-denomination__title">Billetes</h2>
                    <div className="mx-session-denomination__rows">
                      {BILL_DENOMINATIONS.map(renderDenominationRow)}
                    </div>
                  </div>

                  <div className="mx-session-denomination__section">
                    <h2 className="mx-session-denomination__title">Monedas</h2>
                    <div className="mx-session-denomination__rows">
                      {COIN_DENOMINATIONS.map(renderDenominationRow)}
                    </div>
                  </div>

                  <div
                    className="mx-session-denomination-total"
                    aria-live="polite"
                    aria-label={`Total contado ${counted.toFixed(2)} pesos`}
                  >
                    <span className="mx-session-denomination-total__label">
                      Total contado
                    </span>
                    <MoneyDisplay
                      amount={counted}
                      size="lg"
                      emphasized
                      className="mx-session-denomination-total__amount"
                    />
                  </div>
                </div>
              </div>
            </div>

            {error && (
              <div className="mx-close-session-modal__error" role="alert">
                {error}
              </div>
            )}

            {hasDifference && closeNote.trim() === '' && (
              <div className="mx-close-session-modal__hint">
                Escribe una nota explicando la diferencia para continuar.
              </div>
            )}

            <div className="mx-close-session-modal__actions">
              <Button
                variant="secondary"
                size="md"
                onClick={handleClose}
                disabled={submitting}
              >
                Volver
              </Button>
              <Button
                variant="primary"
                size="md"
                onClick={handleGoToConfirm}
                disabled={!canSubmit}
                className="mx-close-session-modal__confirm-btn"
              >
                Continuar
              </Button>
            </div>
          </>
        )}

        {step === 'confirm' && (
          <div className="mx-close-session-modal__confirm">
            <p className="mx-close-session-modal__confirm-text">
              {hasDifference
                ? `Se detectó una diferencia de ${formatDiffLabel}. El cierre es definitivo y no podrá reabrirse. ¿Confirma el cierre con esta diferencia?`
                : 'El efectivo contado coincide con el esperado. El cierre es definitivo. ¿Confirma el cierre de caja?'}
            </p>

            {closeNote.trim() !== '' && (
              <div className="mx-close-session-modal__confirm-note">
                <span className="mx-close-session-modal__confirm-note-label">
                  Nota de cierre:
                </span>
                <span>{closeNote.trim()}</span>
              </div>
            )}

            {error && (
              <div className="mx-close-session-modal__error" role="alert">
                {error}
              </div>
            )}

            <div className="mx-close-session-modal__actions">
              <Button
                variant="secondary"
                size="md"
                onClick={handleBack}
                disabled={submitting}
              >
                Volver
              </Button>
              <Button
                variant="primary"
                size="md"
                onClick={handleSubmit}
                loading={submitting}
                disabled={submitting}
                className="mx-close-session-modal__confirm-btn"
              >
                {submitting ? 'Cerrando caja…' : 'Confirmar cierre'}
              </Button>
            </div>
          </div>
        )}
      </div>
    </Modal>
  );
}

export default CloseSessionModal;
