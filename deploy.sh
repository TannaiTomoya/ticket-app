#!/usr/bin/env bash
# 本番サーバーで実行する更新の手順
set -e

echo "==> アプリのディレクトリへ移動"
cd /var/www/ticket-app

echo "==> main の最新を取得"
git pull origin main

echo "==> PHP の依存をインストール（本番用）"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> フロントエンドの依存をインストールしてビルド"
npm ci && npm run build

echo "==> マイグレーションを実行"
php artisan migrate --force

echo "==> 設定・ルート・ビューをキャッシュ"
php artisan optimize

echo "==> デプロイ完了"
