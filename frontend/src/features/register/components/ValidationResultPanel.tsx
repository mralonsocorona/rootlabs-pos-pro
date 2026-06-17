import { MoneyDisplay } from '../../../components/ui';
import type { CartValidationResponse } from '../types';

interface ValidationResultPanelProps {
  result: CartValidationResponse | null;
}

function formatValidationMessage(message: string): string {
  const insufficientStockMatch = message.match(/^Insufficient stock\. Available: (\d+)\.$/);

  if (insufficientStockMatch) {
    const available = Number(insufficientStockMatch[1]);
    const unitLabel = available === 1 ? 'unidad disponible' : 'unidades disponibles';

    return `Solo hay ${available} ${unitLabel}. Ajusta la cantidad para continuar.`;
  }

  if (message === 'Product is out of stock.') {
    return 'Producto sin stock disponible.';
  }

  if (/^\d+ item\(s\) could not be validated\.$/.test(message)) {
    return 'Hay productos que necesitan revisión antes de cobrar.';
  }

  return message;
}

function ValidationResultPanel({ result }: ValidationResultPanelProps) {
  if (!result) {
    return null;
  }

  const invalidItems = result.items.filter((item) => !item.valid);
  const visibleGlobalErrors = invalidItems.length > 0 ? [] : result.errors;
  const hasGlobalErrors = visibleGlobalErrors.length > 0;

  if (!hasGlobalErrors && invalidItems.length === 0) {
    return null;
  }

  return (
    <div className="mx-register-validation">
      <p className="mx-register-validation__status mx-register-validation__status--invalid">
        Revisa estos productos
      </p>

      {hasGlobalErrors && (
        <div className="mx-register-validation__errors">
          {visibleGlobalErrors.map((err, i) => (
            <p key={i} className="mx-register-validation__error">
              {formatValidationMessage(err)}
            </p>
          ))}
        </div>
      )}

      {invalidItems.length > 0 && (
        <div className="mx-register-validation__items">
          {invalidItems.map((item, i) => (
            <div key={i} className="mx-register-validation__item">
              <div className="mx-register-validation__item-header">
                <span className="mx-register-validation__item-name">
                  {item.name}
                </span>
                <span className="mx-register-validation__item-status mx-register-validation__item-status--invalid">
                  Revisar
                </span>
              </div>
              <div className="mx-register-validation__item-meta">
                <span className="mx-register-validation__item-sku">{item.sku}</span>
                <span className="mx-register-validation__item-qty">
                  Cant: {item.quantity}
                </span>
                <MoneyDisplay
                  amount={parseFloat(item.line_total)}
                  size="sm"
                />
              </div>
              {item.errors.length > 0 && (
                <div className="mx-register-validation__item-errors">
                  {item.errors.map((err, j) => (
                    <p key={j} className="mx-register-validation__item-error">
                      {formatValidationMessage(err)}
                    </p>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

export default ValidationResultPanel;
