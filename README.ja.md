# Laravel + React Starter Kit — i18n Edition

[English](README.md) | 日本語

[公式の Laravel React スターターキット](https://laravel.com/docs/starter-kits) を元に、国際化(i18n)対応を組み込んだフォークです。

## ライブデモ

https://react-starter-kit-i18n-production-sbhkku.laravel.cloud

デモ用のデータベースはシード後に読み取り専用(`chmod 444`)でロックされているため、新規登録・ログインなどの書き込みは database-is-readonly エラーになります。再デプロイのたびにリセットされます。

## 公式キットとの違い

- **日本語をプリセット** — `ja` ロケールが `en` と並んですぐ使える状態で入っている
- **翻訳パイプライン** — `lang/vendor-patches/` でどのロケールを生成するか管理。JSON ファイルを追加して `lang:update` を実行するだけ
- **React 側での翻訳** — Inertia の shared props 経由で、すべての React コンポーネントから `__()` ヘルパーが使える
- **セッションベースのロケール永続化** — `LocalizationBySession` ミドルウェアにより、ページを再読み込みしても選択中のロケールが維持される
- **AI エージェント向けルール** — `.ai/rules/`(upstream には存在しない)に、このフォーク独自の規約(React UI の翻訳方法、`lang/` を生成物として扱うこと、starter-kit 翻訳へのパッチ方法など)をコーディングエージェント向けに記載

## 必要要件

- PHP 8.3+
- Node 20+
- Laravel がサポートするデータベース(ローカル開発では SQLite で動作)

## ロケールの追加

このフォークの React UI にのみ存在する文言(ログイン/2FA/プロフィール画面など)は upstream の `laravel-lang/starter-kits` パッケージには含まれていないため、`lang/vendor-patches/` で上乗せする形になっています:

- `lang/vendor-patches/source/react.json` — このフォークが upstream の `react.json` に追加する英語のソース文言
- `lang/vendor-patches/locales/{locale}.json` — それらの文言のロケールごとの翻訳。完全な例として `lang/vendor-patches/locales/ja.json` を参照

新しいロケール(例: `ko`)を追加する手順:

1. コンポーネントで使っているカスタム文言が `lang/vendor-patches/source/react.json` にまだ無ければ、まずそこに追加する(英語のキー/値のペア)
2. `lang/vendor-patches/locales/ko.json` を作成し、`source/react.json` の全キーを `lang/vendor-patches/locales/ja.json` と同じ構造で翻訳する。あるロケールで upstream が既にその文言を翻訳済みの場合のみキーを省略できる(`ja.json` が `"Warning"` を省略しているのはこの理由)。迷ったら省略せず翻訳する方が安全で、パッチは upstream の値を上書きするだけなので害はない
3. vendor パッケージにパッチをマージし、翻訳ファイルを再生成する:
   ```bash
   composer patch-vendor
   php artisan lang:update
   ```

`composer patch-vendor`(`scripts/patch-vendor.php`)は `lang/vendor-patches/source/` と `lang/vendor-patches/locales/` の内容を `vendor/laravel-lang/starter-kits/` 内の対応するファイルにマージし、あなたのパッチの値を upstream の値より優先します。続く `lang:update` が見つかった全ロケールについて `lang/{locale}.json` と `lang/{locale}/*.php` を再生成します。`patch-vendor` は `composer install`/`composer update` の後に自動実行され、`lang:update` も `composer update` の後に自動実行されますが、新しくロケールを追加した直後はすぐに生成されるよう両方を明示的に実行してください。

## インストール

```bash
composer create-project catatsumuri/react-starter-kit-i18n my-app
cd my-app
composer setup
```

`composer setup` は以下を一括で実行します:

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

## 開発

```bash
composer dev
```

以下の4つのプロセスを並行して起動します:

| プロセス | コマンド |
|---------|------|
| HTTP サーバー | `php artisan serve` |
| キューワーカー | `php artisan queue:listen` |
| ログビューア | `php artisan pail` |
| Vite 開発サーバー | `npm run dev` |

## アーキテクチャ

### バックエンドパッケージ

| パッケージ | 役割 |
|---------|------|
| `laravel-lang/common` | 翻訳パイプラインの中核(`lang:update` コマンド) |
| `laravel-lang/routes` | `LocalizationBySession` ミドルウェア |
| `erag/laravel-lang-sync-inertia` | `lang/{locale}.json` を Inertia の shared props として `lang` に同期 |
| `laravel/wayfinder` | フロントエンド向けの型安全なルート参照 |

### フロントエンド

- React 19 + TypeScript + Vite
- [shadcn/ui](https://ui.shadcn.com) + [Radix UI](https://www.radix-ui.com) コンポーネント
- Tailwind CSS
- `@erag/lang-sync-inertia` — React コンポーネント内で翻訳を扱う `lang()` / `__()` フック

### 翻訳がフロントエンドに届くまで

```
lang/ja.json
    ↓  HandleInertiaRequests::share()
Inertia shared prop: { lang: { "Log in": "ログイン", ... } }
    ↓  @erag/lang-sync-inertia
const { __ } = lang()
    ↓
__('Log in')  →  "ログイン"
```

## コード品質

```bash
composer lint          # PHP (Pint)
npm run check          # JS/TS の lint・フォーマットチェック
npm run types:check    # TypeScript
composer test          # PHPUnit
composer ci:check      # 上記すべて
```

## ライセンス

MIT
