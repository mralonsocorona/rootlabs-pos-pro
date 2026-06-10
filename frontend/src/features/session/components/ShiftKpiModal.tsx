import { useEffect, useState, useCallback } from 'react';
import { Modal, MoneyDisplay, Button } from '../../../components/ui';
import { generateCutX } from '../../cash-cuts/services/cashCutApi';
import { fetchSalesHistory } from '../../sales-history/services/saleHistoryApi';
import { fetchTicket, fetchGiftTicket } from '../../sales/services/ticketApi';
import { writeTicketAndPrint } from '../../sales/utils/printTicket';
import CutSummaryView from '../../cash-cuts/components/CutSummaryView';
import type { CutXResponse } from '../../cash-cuts/types';
import type { SaleHistoryItem } from '../../sales-history/types';


type PosPaymentMethodSlug = 'cash' | 'card';

interface PaymentMethodUpdateResponse {
  sale_id: number;
  wc_order_id: number;
  payment_method: PosPaymentMethodSlug;
  payment_method_label: string;
  old_payment_method?: string;
  old_payment_label?: string;
  cash_action?: string;
  changed: boolean;
  message?: string;
}

function normalizePaymentMethod(value: unknown): PosPaymentMethodSlug {
  return String(value || '').toLowerCase() === 'card' ? 'card' : 'cash';
}

async function updateSalePaymentMethod(
  saleId: number,
  paymentMethod: PosPaymentMethodSlug,
): Promise<PaymentMethodUpdateResponse> {
  const settings = (
    window as unknown as {
      mxPosProSettings?: {
        root?: string;
        nonce?: string;
      };
    }
  ).mxPosProSettings;

  if (!settings?.root || !settings?.nonce) {
    throw new Error('No se pudo leer la configuración del POS.');
  }

  const root = settings.root.replace(/\/?$/, '/');

  const response = await fetch(`${root}sales/${saleId}/payment-method`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': settings.nonce,
      Accept: 'application/json',
    },
    credentials: 'same-origin',
    body: JSON.stringify({ payment_method: paymentMethod }),
  });

  const body = await response.json().catch(() => null);

  if (!response.ok) {
    throw new Error(
      body && typeof body.message === 'string'
        ? body.message
        : 'No se pudo cambiar el método de pago.',
    );
  }

  return body as PaymentMethodUpdateResponse;
}


interface ShiftKpiModalProps {
  open: boolean;
  sessionId: number;
  onClose: () => void;
}

function ShiftKpiModal({ open, sessionId, onClose }: ShiftKpiModalProps) {
  const [cutData, setCutData] = useState<CutXResponse | null>(null);
  const [sales, setSales] = useState<SaleHistoryItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [printing, setPrinting] = useState<{ saleId: number; type: 'ticket' | 'gift' } | null>(null);
  const [printError, setPrintError] = useState<string | null>(null);

  const [paymentMethodDrafts, setPaymentMethodDrafts] = useState<Record<number, PosPaymentMethodSlug>>({});
  const [updatingPaymentMethodSaleId, setUpdatingPaymentMethodSaleId] = useState<number | null>(null);

  const loadData = useCallback(async () => {
    if (!sessionId) return;
    setLoading(true);
    setError(null);
    try {
      const [cutResult, salesResult] = await Promise.all([
        generateCutX(sessionId),
        fetchSalesHistory(
          {
            date_from: '',
            date_to: '',
            status: '',
            cashier_id: null,
            search: '',
            sessionId: sessionId,
          },
          1,
          100
        ),
      ]);
      setCutData(cutResult);
      setSales(salesResult.items);
      setPrintError(null);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Error cargando datos del turno');
    } finally {
      setLoading(false);
    }
  }, [sessionId]);

  useEffect(() => {
    if (open) {
      void loadData();
    } else {
      setCutData(null);
      setSales([]);
      setPrintError(null);
      setPrinting(null);
    }
  }, [open, loadData]);

  const doPrint = useCallback(async (saleId: number, useGift: boolean) => {
    const type = useGift ? 'gift' : 'ticket';
    const win = window.open('', '_blank', 'width=420,height=760');

    if (!win) {
      setPrintError('No se pudo abrir la ventana de impresión. Permite ventanas emergentes para este sitio.');
      return;
    }

    setPrinting({ saleId, type });
    setPrintError(null);

    try {
      const html = useGift
        ? await fetchGiftTicket(saleId)
        : await fetchTicket(saleId);
      writeTicketAndPrint(win, html);
    } catch (err) {
      setPrintError(err instanceof Error ? err.message : 'No se pudo generar el ticket');
      win.close();
    } finally {
      setPrinting(null);
    }
  }, []);

  const handlePaymentMethodDraftChange = useCallback(
    (saleId: number, paymentMethod: PosPaymentMethodSlug) => {
      setPaymentMethodDrafts((current) => ({
        ...current,
        [saleId]: paymentMethod,
      }));
    },
    [],
  );

  const handlePaymentMethodChange = useCallback(
    async (saleId: number) => {
      const sale = sales.find((item) => item.id === saleId);
      const targetMethod =
        paymentMethodDrafts[saleId] ?? normalizePaymentMethod(sale?.payment_method);

      const label = targetMethod === 'card' ? 'Tarjeta' : 'Efectivo';
      const confirmed = window.confirm(
        `¿Cambiar el método de pago a ${label}? Esto ajustará también el cierre de caja.`,
      );

      if (!confirmed) {
        return;
      }

      setUpdatingPaymentMethodSaleId(saleId);
      setPrintError(null);

      try {
        const result = await updateSalePaymentMethod(saleId, targetMethod);

        setSales((current) =>
          current.map((item) =>
            item.id === result.sale_id
              ? {
                  ...item,
                  payment_method: result.payment_method,
                  payment_method_label: result.payment_method_label,
                }
              : item,
          ),
        );

        setPaymentMethodDrafts((current) => {
          const next = { ...current };
          delete next[result.sale_id];
          return next;
        });
      } catch (err) {
        setPrintError(err instanceof Error ? err.message : 'No se pudo cambiar el método de pago.');
      } finally {
        setUpdatingPaymentMethodSaleId(null);
      }
    },
    [paymentMethodDrafts, sales],
  );



  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ventas del Turno"
      panelClassName="mx-cut-modal-panel mx-cut-x-modal-panel"
    >
      <div className="mx-cut-modal">
        {loading && (
          <div className="mx-cut-modal__loading">
            <p>Calculando estadísticas...</p>
          </div>
        )}

        {error && !loading && (
          <div className="mx-cut-modal__error" role="alert">
            <p>{error}</p>
            <Button variant="secondary" size="sm" onClick={loadData}>
              Reintentar
            </Button>
          </div>
        )}

        {!loading && !error && cutData && (
          <>
            <div className="mx-cut-x-modal__columns">
              <CutSummaryView summary={cutData.cut} />

              <div className="mx-cut-x-modal__counter" style={{ display: 'flex', flexDirection: 'column' }}>
                <div className="mx-cut-x-modal__counter-header">
                  <h3 className="mx-cut-x-modal__counter-title">Listado de Ventas ({sales.length})</h3>
                  <p className="mx-cut-x-modal__counter-hint">
                    Últimas ventas procesadas en el turno actual.
                  </p>
                </div>

                <div className="mx-kpi-table-container">
                  <table className="mx-kpi-table">
                    <thead>
                      <tr>
                        <th># Orden</th>
                        <th>Fecha</th>
                        <th>Método</th>
                        <th>Ticket</th>
                        <th>Ticket regalo</th>
                        <th style={{ textAlign: 'right' }}>Neto</th>
                      </tr>
                    </thead>
                    <tbody>
                      {sales.length === 0 ? (
                        <tr>
                          <td colSpan={6} className="mx-kpi-table-empty">
                            No hay ventas en este turno
                          </td>
                        </tr>
                      ) : (
                        sales.map((sale) => (
                          <tr key={sale.id}>
                            <td>#{sale.wc_order_id}</td>
                            <td>
                              {new Date(sale.created_at).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit',
                              })}
                            </td>
                            <td className="mx-kpi-table__method">
                  <div className="mx-pos-payment-method-hotfix-control">
                    <select
                      className="mx-pos-payment-method-hotfix-select"
                      value={paymentMethodDrafts[sale.id] ?? normalizePaymentMethod(sale.payment_method)}
                      disabled={updatingPaymentMethodSaleId === sale.id}
                      onChange={(event) =>
                        handlePaymentMethodDraftChange(
                          sale.id,
                          event.target.value as PosPaymentMethodSlug,
                        )
                      }
                    >
                      <option value="cash">Efectivo</option>
                      <option value="card">Tarjeta</option>
                    </select>
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => handlePaymentMethodChange(sale.id)}
                      disabled={updatingPaymentMethodSaleId === sale.id}
                    >
                      Cambiar
                    </Button>
                  </div>
                </td>
                            <td className="mx-kpi-table__action">
                              <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => doPrint(sale.id, false)}
                                loading={printing?.saleId === sale.id && printing.type === 'ticket'}
                                disabled={printing !== null}
                              >
                                Ticket
                              </Button>
                            </td>
                            <td className="mx-kpi-table__action">
                              <Button
                                variant="secondary"
                                size="sm"
                                onClick={() => doPrint(sale.id, true)}
                                loading={printing?.saleId === sale.id && printing.type === 'gift'}
                                disabled={printing !== null}
                              >
                                Regalo
                              </Button>
                            </td>
                            <td style={{ textAlign: 'right' }}>
                              <MoneyDisplay amount={parseFloat(sale.net_total)} />
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                  </table>
                </div>

                {printError && (
                  <p className="mx-kpi-table-print-error" role="alert">
                    {printError}
                  </p>
                )}
              </div>
            </div>

            <div className="mx-cut-modal__actions">
              <Button variant="primary" size="md" onClick={onClose}>
                Cerrar
              </Button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}

export default ShiftKpiModal;
