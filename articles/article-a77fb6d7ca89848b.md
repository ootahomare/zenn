---
title: "DDD の集約境界をどう設計するか 〜巨大テーブルとの向き合い方〜"
emoji: "🦉"
type: "idea"
topics: ["DDD", "CakePHP4", "PHP8", "ドメイン駆動設計"]
published: false
---

# 集約境界をどう設計するか  
## ― テーブルではなく“不変条件”から逆算する設計 ―

前回の記事では、CakePHP の Entity がスキーマに強く依存し、  
巨大テーブルが肥大化したモデルを生む問題について触れました。

今回のテーマは、その根っこにある **「集約をどう定義するか」** です。
このあたりはまだ勉強中なのですが、
自分なりに整理してみた内容をまとめてみました…！

> DDD における集約は「テーブル」ではなく、  
> **ビジネス上、一貫して整合性を保つべき“意味のまとまり”** を基準に決める。

この記事では、  
DB 構造を変えられない現場でも実践できる **集約の決め方、見極め方、吸収の仕方** をケーススタディで考えていきます。

---

# 1. そもそも集約とは何か？

DDD における集約（Aggregate）は、

> **不変条件（Invariant）を守るための最小単位**

として説明されています。

ポイントは次のとおり：

- 集約は **一貫性を保つ必要がある領域** をまとめたもの
- 1つの集約内では **トランザクションを張れる**（＝整合性を保証できる）
- 外部集約とは **疎結合** であるべき
- 集約ごとに **集約ルート（Aggregate Root）** が存在する

### ❌ よくある誤解  
- テーブル = 集約  
- 画面 = 集約単位  
- 機能単位 = 集約  

---

# 2. 集約境界は「不変条件」から逆算する

不変条件とは、

> **「常に守られていなければならないビジネスルール」**

のこと。

例）  
- ユーザーのステータスとロック状態の関係  
- 予約数と空き枠の関係  
- 注文と在庫数の関係  

この“不変条件”を守るために、「同じ集約にすべき項目」を考えていきます。

---

# 3. ケーススタディ：巨大 users テーブルのどこが集約なのか？

仮に `users` テーブルが 120 カラムあったとしても、
よく見るとすべてが同じ集約に属するわけではないです。

### 例：users テーブル（120 カラム）

| カテゴリ | カラム例 | 集約に含める？ |
|---------|----------|----------------|
| 基本情報 | name, email | ✔ 含める |
| 認証情報 | password_hash, two_factor_flag | ✔ 含める |
| 契約情報 | plan_id, expired_at | ✔ 別集約の可能性 |
| 課金情報 | stripe_customer_id, last_payment_at | ❌ ほぼ外部コンテキスト |
| 分析用 | last_login_ip, login_count | ❌ 含めない |
| 内部フラグ | imported_flag, legacy_id | ❌ インフラ用の情報 |

### ✔ 集約はこうなる：

- **User（アカウント情報）集約**  
- **UserSecurity（認証）集約**  
- **UserContract（契約）集約**  
- **UserStats（統計情報）…※集約外**

巨大テーブルでも、意味のまとまりは分割できますね。

---

# 4. テーブルと集約が一致しない場合の吸収ポイント

一致しないことの方がメジャーだと思います。そのズレをどこで吸収するかが大切ですね。

答えは：

1. **Repository（永続化の境界）**  
2. **Mapper（データ構造の変換）**  
3. **Finder（SELECT カラム最適化）**

CakePHP のインフラ依存を Repository に閉じ込めることで、  
ドメインは純粋な集約だけに集中できる。

> この記事の「Repository / Mapper / Finder」の構成は、前回の記事  
**「CakePHP と DDD 〜集約と巨大テーブルのズレをどう吸収するか〜」**  
でさらに詳しく紹介しています。

---

# 5. DDD 集約モデルの実装例

## 5-1. Domain Model（集約ルート）

```php
class User
{
    public function __construct(
        UserId $id,
        UserName $name,
        UserEmail $email,
        UserStatus $status,
        SecuritySettings $security
    ) {}

    public function activate(): void
    {
        $this->status = UserStatus::active();
    }
}
```

## 5-2. Repository Interface（Domain）

```php
interface UserRepositoryInterface
{
    public function find(UserId $id): ?User;
    public function save(User $user): void;
}
```

---

# 6. インフラ層で巨大テーブルを扱う工夫（Finder）

```php
public function findForUserAggregate(Query $q)
{
    return $q->select([
        'id',
        'name',
        'email',
        'status',
        'two_factor_flag',
        'locked',
    ]);
}
```

→ 集約に関係ない 100 カラムは **取得しない**。

---

# 7. Mapper が“ズレ”をすべて吸収する

```php
class UserMapper
{
    public function toDomain(UserEntity $e): User
    {
        return new User(
            new UserId($e->id),
            new UserName($e->name),
            new UserEmail($e->email),
            UserStatus::from($e->status),
            new SecuritySettings(
                $e->two_factor_flag,
                $e->locked
            )
        );
    }
}
```

---

# 8. 集約を守るためのトランザクション境界

集約内では一貫性を保証するため、  
**ApplicationService でトランザクションを張る**。

仕事は最後まで完結させるイメージです。

```php
public function handle(UpdateUserCommand $cmd)
{
    return $this->transaction->run(function () use ($cmd) {

        $user = $this->repo->find(new UserId($cmd->id));

        $user->activate();

        $this->repo->save($user);
    });
}
```

---

# 9. まとめ：集約はテーブルではなく“意味”で決める

- 集約境界は「不変条件」で定義する  
- 120 カラムあるテーブルでも集約は 10〜20 カラムに収まる  
- テーブルと集約のズレは Repository / Mapper / Finder が吸収  
- CakePHP の便利さはインフラ層に閉じ込め、ドメインは純粋にする  

> **巨大テーブルの現場では、こうした集約設計の考え方が、とても役に立つ場面が多い気がしています。**


### 参考文献  
- Eric Evans『Domain-Driven Design — Tackling Complexity in the Heart of Software』  
- Vaughn Vernon『Implementing Domain-Driven Design』  

## ひとこと（あとがき）
集約って、本当に“経験がものを言う”分野だなぁ…と感じています。
わたし自身まだまだ学んでいる途中なので、  
できれば先輩エンジニアの考え方もたくさん吸収しながら成長していきたいところです。

それでも、CakePHP の大きな Entity をそのまま使うより、  
意味ごとに分けてあげたほうが “ドメインの理解がしやすくなる” 感覚はすごくあって…。

少しずつでもこういう考え方を現場に広げていけたらいいな、って思っています。
