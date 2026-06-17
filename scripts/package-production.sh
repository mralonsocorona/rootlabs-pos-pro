#!/usr/bin/env bash

echo "== Package limpio producción mx-pos-pro =="

FAILED=0

fail() {
  echo "❌ $1"
  FAILED=1
}

ok() {
  echo "✅ $1"
}

ROOT="$(pwd)"
PLUGIN_SLUG="mx-pos-pro"
PUBLIC_SLUG="rootlabs-pos-for-woocommerce"
VERSION="$(node -p "require('./package.json').version" 2>/dev/null || echo "0.1.0")"
TS="$(date +%Y%m%d-%H%M%S)"
PACKAGE_DIR="$ROOT/../packages"
STAGING="/tmp/${PLUGIN_SLUG}-production-${TS}"
ZIP="$PACKAGE_DIR/${PUBLIC_SLUG}-v${VERSION}.zip"

REQUIRED_SOURCE=(
  "mx-pos-pro.php"
  "uninstall.php"
  "includes/Frontend/PosRoute.php"
  "templates/frontend/pos-login.php"
  "templates/frontend/pos-open-cash.php"
  "templates/frontend/pos-count-cash.php"
  "templates/frontend/pos-shell.php"
  "assets/dist/assets/index.js"
  "assets/dist/assets/index.css"
  "assets/dist/index.html"
)

echo
echo "== 1. Validar raíz =="
[ -f "mx-pos-pro.php" ] && ok "mx-pos-pro.php encontrado" || fail "No estás en la raíz del plugin"

echo
echo "== 2. Validar source local =="
for f in "${REQUIRED_SOURCE[@]}"; do
  [ -f "$f" ] && ok "$f" || fail "Falta en source local: $f"
done

echo
echo "== 3. Validar dist de producción sincronizado =="
for f in assets/dist/index.html assets/dist/assets/index.js assets/dist/assets/index.css; do
  [ -f "$f" ] && ok "$f" || fail "Falta dist de producción: $f"
done

grep -q "Stock máximo agregado" assets/dist/assets/index.js \
  && grep -q "Carrito restaurado" assets/dist/assets/index.js \
  && grep -q "Existencias actualizadas" assets/dist/assets/index.js \
  && ok "dist contiene hotfix probado en servidor" \
  || fail "dist no contiene los hotfixes probados en servidor"

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== 4. PHP lint source =="
  php -l mx-pos-pro.php
  PHP_MAIN="$?"

  php -l uninstall.php
  PHP_UNINSTALL="$?"

  find includes templates -type f -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "No syntax errors detected"
  PHP_TREE="${PIPESTATUS[0]}"

  if [ "$PHP_MAIN" -ne 0 ] || [ "$PHP_UNINSTALL" -ne 0 ] || [ "$PHP_TREE" -ne 0 ]; then
    fail "PHP lint falló"
  else
    ok "PHP lint OK"
  fi
fi

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== 5. Crear staging limpio =="
  mkdir -p "$PACKAGE_DIR"
  rm -rf "$STAGING"
  mkdir -p "$STAGING/$PLUGIN_SLUG"

  rsync -av ./ "$STAGING/$PLUGIN_SLUG/" \
    --exclude "/.git/" \
    --exclude "/node_modules/" \
    --exclude "/frontend/" \
    --exclude "/build/" \
    --exclude "/docs/" \
    --exclude "/README.md" \
    --exclude "/CHANGELOG.md" \
    --exclude "/CONTRIBUTING.md" \
    --exclude "/SECURITY.md" \
    --exclude "/ROADMAP.md" \
    --exclude "/.gitignore" \
    --exclude "/scripts/" \
    --exclude "/packages/" \
    --exclude ".DS_Store" \
    --exclude "/package.json" \
    --exclude "/package-lock.json" \
    --exclude "/pnpm-lock.yaml" \
    --exclude "/readme.txt" \
    --exclude "/languages/" \
    --exclude "/tsconfig.json" \
    --exclude "/vite.config.ts" \
    --exclude "/vite-env.d.ts"

  [ "$?" -eq 0 ] && ok "Staging creado" || fail "rsync falló"
fi

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== 6. Validar staging =="
  for f in "${REQUIRED_SOURCE[@]}"; do
    [ -f "$STAGING/$PLUGIN_SLUG/$f" ] && ok "staging/$f" || fail "Falta en staging: $f"
  done
fi

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== 7. Crear ZIP =="
  rm -f "$ZIP"
  (
    cd "$STAGING"
    zip -r "$ZIP" "$PLUGIN_SLUG"
  )

  [ "$?" -eq 0 ] && ok "ZIP creado: $ZIP" || fail "zip falló"
fi

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== 8. Validar ZIP final =="

  for f in "${REQUIRED_SOURCE[@]}"; do
    unzip -l "$ZIP" | grep -q "mx-pos-pro/$f"
    [ "$?" -eq 0 ] && ok "ZIP/$f" || fail "Falta en ZIP: $f"
  done

  echo
  echo "== Confirmar que NO se coló basura =="
  unzip -l "$ZIP" | grep -E "mx-pos-pro/build/|mx-pos-pro/frontend/|mx-pos-pro/docs/|mx-pos-pro/scripts/|node_modules/|\.git/|package\.json|package-lock\.json|pnpm-lock\.yaml|readme\.txt|mx-pos-pro/languages/|tsconfig\.json|vite\.config\.ts|README\.md|CHANGELOG\.md|CONTRIBUTING\.md|SECURITY\.md|ROADMAP\.md|\.gitignore"

  if [ "$?" -eq 0 ]; then
    fail "El ZIP contiene archivos de desarrollo o build viejo."
  else
    ok "ZIP limpio sin build viejo ni dev files"
  fi
fi

if [ "$FAILED" -eq 0 ]; then
  echo
  echo "== ZIP FINAL OK =="
  ls -lh "$ZIP"
  echo "$ZIP"
else
  echo
  echo "No se generó ZIP válido."
fi

echo
echo "== Fin =="

# Return a failing status when validation failed.
# We intentionally avoid an explicit "exit 1" so the script is safer if sourced accidentally.
if [ "$FAILED" -eq 0 ]; then
  true
else
  false
fi
