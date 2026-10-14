@AGENTS.md
# まちチケ
地域の小さなイベントのチケットを売るアプリ。主催者がイベントと券種を用意し、参加者は Stripe Checkout で支払って購入する。

## コードの決まり
- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）。Volt は使わない
- UI は Flux UI の Free 版にあるものだけ。足りない部品は Tailwind で組む
- 画面の文言は日本語。日時は `isoFormat('M月D日(ddd) HH:mm')`、金額は「3,000円」と表示する
- 権限の判定は `app/Policies/` に書き、画面からは `$this->authorize()` で呼ぶ
- Stripe など外部サービスを呼ぶのは `app/Services/` のクラスだけ。テストではそのクラスを差し替える
- 機能を足したら `tests/Feature/` に Pest のテストも足し、`/check` を通す
- コマンドは必ず Sail から（`sail artisan`、`sail composer`、`sail npm`）。ホストの php・composer は使わない
- ビューを触ったら `sail npm run build` で Tailwind のクラスを拾い直す

## 進め方
- 実装の前に、どのファイルに何を書くかを短く伝える
- 1回の依頼で扱うのは1機能まで。大きいときは分け方を先に示す
- 書き方は、同じフォルダにある既存のファイルにそろえる
- `.env` の値・API キー・パスワードは、コードにもコミットにも入れない
- コマンドが失敗したら、原因の見立てと対処を1つずつ示してからやり直す

## 設計
資料: [データベース設計](doc/design/database.md)、[画面一覧](doc/design/screens.md)、[設計判断](doc/design/decisions.md)、[決済の流れ・テスト・初期データ](doc/spec/04_flows_tests_seed.md)

### 言葉の使い分け
- イベント（events）は主催者が開く催し。券種（ticket_types）はその中の「一般」「学生」などで、価格・定員・販売期間を持つ
- 注文（orders）は1イベントぶんの購入1回。明細（order_items）は券種ごとの枚数と、買った時点の単価。チケット（tickets）は支払い後に1枚ずつ発行される

### データの扱い
- ロールは `users.role`（Enum `UserRole`: customer / organizer / admin）。`role` や各種 `status` は一括代入で書き換えさせない
- イベントの持ち主は `events.organizer_id`（`organizer()` / `organizedEvents()`）、注文した人は `orders.user_id`
- 状態は Enum（`app/Enums/`）で持つ。`events.status` は draft / published / unpublished、`orders.status` は pending / paid / cancelled / refunded。表示用に `label()` と `color()` がある
- 金額はすべて円の整数。明細の `unit_price` には買った時点の価格を残し、あとで券種の価格を見に行かない
- 残り枚数は保存せず、その都度「定員 −（支払い済み ＋ 作成から15分以内の支払い待ち）」で出す。条件は `Order::holdingSeats()`（15分は `Order::HOLD_MINUTES`）、計算は `TicketType::remaining()` / `withHeldQuantity()`。購入ではトランザクションの中で券種の行を `lockForUpdate` してから数える
- チケットの `code` は作成時に自動で付く ULID
- 注文が付いたイベント・券種・ユーザーは消せない（外部キーは restrict）。券種・明細・チケットは親を消すと一緒に消える（cascade）
- テストのデータはファクトリの state で作る（`organizer()`、`published()`、`past()`、`saleEnded()`、`paid()`、`cancelled()` など）

### 画面とルート
- 誰でも見られる: `/`（公開中で開催前のものを、開催日の近い順に）、`/events/{event}`（下書き・非公開は、持ち主と管理者以外には 404）
- 主催者: `/organizer/events`、`/organizer/events/create`、`/organizer/events/{event}/edit`（券種の追加・編集と公開の切り替えもこの画面）。他人のイベントは 403
- 購入のあと: `/orders`、`/orders/{order}`、`/orders/{order}/complete`、`/orders/{order}/cancel`、`/organizer/events/{event}/orders`
- 支払いの通知は `POST /stripe/webhook`（CSRF の対象外）。paid への切り替えは「まだ pending の注文だけ」を条件にして行う

## 工程
番号は [要件定義](doc/spec/01_requirements.md) の機能（C / E）とテスト（T）。工程は1つずつ進め、終えるたびに `/check` を通してコミットする

### 済み
- 6テーブルのモデル・マイグレーション・ファクトリと Enum、日本語の言語ファイル（laravel-lang）、CI（MySQL 8.4）
- 工程1〜4: ロールと `role` ミドルウェア、主催者のイベント管理と公開の切り替え（`EventPolicy`、`events.ends_at` を追加）、券種の管理（編集画面に埋め込んだ `⚡ticket-types`）、公開側の一覧・詳細と残り枚数、ロールごとのナビ。タイムゾーンは Asia/Tokyo
- 工程5: 購入の注文作成（C8, C9）。詳細で券種ごとに枚数を選ぶ → 購入確認 `/events/{event}/purchase` → `App\Services\OrderService::createOrder`（`lockForUpdate`、単価は DB から）→ 完了 `/orders/{order}/complete`（`OrderPolicy::view`。工程7で Stripe へ差し替える） → T6〜T8

### 決めたこと
- 券種の販売期間の列名は `sale_starts_at` / `sale_ends_at`。チケットの `code` は UUID ではなく ULID
- 一覧に出す「開催前」は `starts_at > now()` で判定する。公開するには券種が1件以上必要で、公開中の最後の券種は消せない
- イベントは注文が1件でもあれば消せない（外部キーが restrict のため、支払い済みだけで判定するより厳しくしている）。券種は明細があれば消せない
- 公開の切り替えと券種の操作は、イベントの `update` 権限で守る。管理者が非公開にしたイベントを、主催者は公開に戻せない（403）
- 入力と検証はフォームオブジェクト（`app/Livewire/Forms/`）、処理はクラスに置く（`⚡*.blade.php` は phpstan が見ないため）
- 公開側の画面は `layouts/public.blade.php`、ログイン後の画面はこれまでの `layouts/app`
- 購入のルート（購入確認・完了）は `auth` だけで守り、`verified` は付けない。ゲストが詳細で選ぶと、選択をセッション `purchase.{event_id}` に残してログインへ送り、ログイン後に購入確認へ戻す
- 1回に買えるのは券種ごとに1〜10枚（`OrderService::MAX_QUANTITY`）。「終わったイベント」は `Event::isFinished()`（`ends_at` が過去）で判定し、下書き・非公開・終了済みは買えない
- 買えない理由は `PurchaseException` で投げ、画面で `addError('purchase')` → `flux:callout` で出す。`Order` / `OrderItem` は `$fillable` を持たず、プロパティに直接入れて保存する
- `OrderPolicy::view` は本人・そのイベントの主催者・管理者に許す

### 段階1（残り）
6. エラー文の項目名を日本語にする（C17）: `lang/ja/validation.php` の `attributes` に足す

### 段階2（決済とメール。`feature/payments` ブランチで進める。詳細は [決済とメールの流れ](doc/spec/04_flows_tests_seed.md)）
7. Stripe Checkout（C10）: `CheckoutService`（createSession / retrieveSession / expireSession）。中断したら Session を失効させてから cancelled にする
8. 支払いの通知と完了ページ（C11, C13）: `OrderService::markAsPaid` は `WHERE status = pending` を付けた UPDATE で確定する。0円の注文もここを通す。`orders.stripe_payment_intent_id` を追加 → T9〜T11
9. 購入完了メールとマイページ（C12, C14）: メールは `markAsPaid` が true を返したときだけ送る。`OrderPolicy::view` は本人・そのイベントの主催者・管理者に許す → T12
10. 売上画面と仕上げ（C16）: `/organizer/events/{event}/orders` は `role:organizer,admin`。定員を下げられるのは「支払い済み ＋ 15分以内の支払い待ち」まで。イベントの開始日時を券種の販売終了より前にさせない。最後に security-audit で点検する
11. シーダー: [決済とメールの流れ](doc/spec/04_flows_tests_seed.md) の「初期データ」に合わせる（パスワードは全員 `password`）

### 公開
12. 本番公開: `.env.example` を整え、`APP_ENV=production` で動きを確かめ、本番の Webhook を登録する
13. 自動デプロイ: `.github/workflows/` にデプロイの流れを足す（テストの `tests.yml` はある）

### 拡張（基本機能のあと）
- 早めにやると効くもの: E2（同時購入。購入処理の `lockForUpdate`）、E8（セキュリティ点検）、E9（N+1）
- 列を足すもの: E14 `events.image_path`、E1 `tickets.checked_in_at`（E3 の `OrderStatus::Refunded` は追加済み）
