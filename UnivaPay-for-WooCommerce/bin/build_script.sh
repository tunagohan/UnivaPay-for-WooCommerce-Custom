#!/usr/bin/env bash
set -euo pipefail

# ===== 設定（必要なら書き換え） =====
SERVICE=${SERVICE:-wp}  # docker compose のサービス名
PLUGIN_SLUG=${PLUGIN_SLUG:-UnivaPay-for-WooCommerce}
PLUGIN_PATH_IN_CONTAINER="/var/www/html/wp-content/plugins/${PLUGIN_SLUG}"
OUT_DIR_HOST=${OUT_DIR_HOST:-"$PWD"}   # 取り出し先（ホスト）
RUN_TESTS=${RUN_TESTS:-false}          # true にすると PHPUnit を走らせる
RUN_BUILD=${RUN_BUILD:-false}          # true にすると npm build を実行（dist を再生成）
# ===================================

echo ">>> Detect plugin version inside container…"
PLUGIN_MAIN="${PLUGIN_PATH_IN_CONTAINER}/${PLUGIN_SLUG}.php"
VERSION=$(docker compose exec -T "${SERVICE}" bash -lc "grep -E '^\\s*\\*\\s*Version:' '${PLUGIN_MAIN}' | sed -E 's/.*Version:\\s*([^- ]+).*/\\1/'" | tr -d '\r')
NAME_SUFFIX=$(docker compose exec -T "${SERVICE}" bash -lc "grep -E '^\\s*\\*\\s*Plugin Name:' '${PLUGIN_MAIN}' | sed -E 's/.*Plugin Name:\\s*(.*)/\\1/'" | tr -d '\r')

# 万一 Version が取れないときの保険
if [[ -z "${VERSION}" ]]; then
  VERSION="0.0.0-custom"
fi

ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH_IN_CONTAINER="/var/www/html/wp-content/plugins/${ZIP_NAME}"

echo ">>> Plugin: ${NAME_SUFFIX}"
echo ">>> Version: ${VERSION}"
echo ">>> Service: ${SERVICE}"
echo ">>> Plugin dir: ${PLUGIN_PATH_IN_CONTAINER}"
echo ">>> ZIP: ${ZIP_PATH_IN_CONTAINER}"

if [[ "${RUN_BUILD}" == "true" ]]; then
  echo ">>> npm build（必要に応じて）を実行します…"
  docker compose exec -T "${SERVICE}" bash -lc "
    set -e
    cd '${PLUGIN_PATH_IN_CONTAINER}'
    if [ -f package.json ]; then
      if command -v npm >/dev/null 2>&1; then
        npm ci || npm install
        npm run build
      else
        echo 'npm がコンテナにありません。RUN_BUILD=false にするか、コンテナに npm を入れてください' >&2
        exit 1
      fi
    else
      echo 'package.json が無いので npm build はスキップします'
    fi
  "
fi

if [[ "${RUN_TESTS}" == "true" ]]; then
  echo ">>> PHPUnit を実行します…"
  docker compose exec -T "${SERVICE}" bash -lc "
    set -e
    cd '${PLUGIN_PATH_IN_CONTAINER}'
    if command -v composer >/dev/null 2>&1; then
      composer test
    else
      echo 'composer がコンテナにありません。RUN_TESTS=false にするか、コンテナに composer を入れてください' >&2
      exit 1
    fi
  "
fi

echo ">>> ZIP を作成します…（開発用ファイルを除外）"
docker compose exec -T "${SERVICE}" bash -lc "
  set -e
  cd /var/www/html/wp-content/plugins
  rm -f '${ZIP_NAME}'
  if ! command -v zip >/dev/null 2>&1; then
    echo 'zip コマンドがコンテナにありません。apt install -y zip などで導入してください。' >&2
    exit 1
  fi
  zip -r '${ZIP_NAME}' '${PLUGIN_SLUG}' \
    -x '*/.git/*' '*/.github/*' '*/node_modules/*' '*/tests/*' '*.DS_Store' \
       '*/.DS_Store' '*/.idea/*' '*/.vscode/*' '*/composer.lock' '*/package-lock.json'
"

echo ">>> ZIP をホストへコピーします…"
docker cp "${SERVICE}:${ZIP_PATH_IN_CONTAINER}" "${OUT_DIR_HOST}/"

echo ">>> 完了: ${OUT_DIR_HOST}/${ZIP_NAME}"
