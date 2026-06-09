import { useCallback, useState } from 'react';
import { Input } from '../../../components/ui';
import { useLiveProductSearch } from '../hooks/useLiveProductSearch';
import ProductResultCard from './ProductResultCard';
import VariationPickerModal from './VariationPickerModal';
import type { CatalogProduct, IndexedProduct } from '../types';

interface ProductSearchPanelProps {
  onAddToCart: (product: IndexedProduct) => void;
}

function ProductSearchPanel({ onAddToCart }: ProductSearchPanelProps) {
  const [query, setQuery] = useState('');
  const [selectedProduct, setSelectedProduct] =
    useState<CatalogProduct | null>(null);
  const { results, loading, refreshing, error, searched } =
    useLiveProductSearch(query, 24);

  const handleAddToCart = useCallback(
    (product: IndexedProduct) => {
      onAddToCart(product);
      setQuery('');
      setSelectedProduct(null);
    },
    [onAddToCart],
  );

  return (
    <div className="mx-register-search">
      <div className="mx-register-search__input-wrap">
        <Input
          id="register-search"
          type="search"
          placeholder="Busca por SKU o nombre..."
          value={query}
          onChange={(e) => setQuery((e.target as HTMLInputElement).value)}
        />
        {refreshing && (
          <span
            className="mx-register-search__refresh"
            aria-label="Actualizando resultados"
          >
            <span className="mx-ui-button__spinner" aria-hidden="true" />
          </span>
        )}
      </div>

      {loading && results.length === 0 && (
        <div className="mx-register-search__state" aria-busy="true">
          <span className="mx-ui-button__spinner" aria-hidden="true" />
          <p>Buscando...</p>
        </div>
      )}

      {error && results.length === 0 && (
        <div className="mx-register-search__state" role="alert">
          <p className="mx-register-search__error">{error}</p>
        </div>
      )}

      {!loading && !error && searched && results.length === 0 && (
        <div className="mx-register-search__state">
          <p className="mx-register-search__empty-title">
            {query.trim().length === 1
              ? 'Escribe al menos 2 caracteres para buscar.'
              : 'No se encontraron productos'}
          </p>
          <p className="mx-register-search__empty-hint">
            Prueba con otro SKU o nombre.
          </p>
        </div>
      )}

      {results.length > 0 && (
        <div
          className="mx-register-search-results"
          aria-busy={refreshing}
        >
          {results.map((product) => (
            <ProductResultCard
              key={product.product_id}
              product={product}
              onAddToCart={handleAddToCart}
              onSelectVariations={setSelectedProduct}
            />
          ))}
        </div>
      )}

      {error && results.length > 0 && (
        <p className="mx-register-search__inline-error" role="alert">
          {error}
        </p>
      )}

      <VariationPickerModal
        open={selectedProduct !== null}
        product={selectedProduct}
        onClose={() => setSelectedProduct(null)}
        onAddToCart={handleAddToCart}
      />
    </div>
  );
}

export default ProductSearchPanel;
