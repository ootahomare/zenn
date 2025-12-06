---
title: "集約と巨大テーブルのズレをどう吸収するか"
emoji: "🦉"
type: "idea"
topics: ["CakePHP4", "PHP8", "ドメイン駆動設計", "DDD"]
published: false
---

# 集約と巨大テーブルのズレをどう吸収するか

## ― DB 変更不可の現場で輝く DDD の“境界線”設計 ―

## はじめに：DB 変更不可の現場で起こる“肥大化モデル”問題

既存システムでは **DB 設計を変更できない** という前提が珍しくありません。  
長年の機能追加でテーブルが巨大化し、100 カラム以上ある `users` テーブルもよく見かけます。

そして CakePHP は **スキーマ依存の ORM Entity** を採用しているため、  
テーブルが巨大になれば Entity も巨大になります。

- UI（FormHelper）
- バリデーション
- 永続化処理
- API 入出力  
  すべてがこの 1 つの Entity に依存するため、設計が “DB 構造中心” になってしまいます。

---

# 1. CakePHP の ORM Entity はスキーマ 100％依存

CakePHP の Entity は DB テーブルの構造そのままです。

```php
class UserEntity extends Entity
{
    // users テーブルの 120 カラムがすべて並ぶ
}
```

これがもたらす問題：

- カラムが増えるたびに Entity が肥大化
- UI も永続化もすべて影響を受ける
- ApplicationService が複雑化
- どこが「本当に意味のあるデータ」なのか分かりづらくなる
- モデルがビジネスロジックを表さなくなる

> **巨大テーブルがアプリケーション全体を支配してしまう構造。**

---

# 2. DDD のアプローチ：集約は“意味のまとまり”で切る

DDD では、集約（Aggregate）は次の基準で設計します：

> **「ビジネスルール上一貫して扱うべき意味のまとまりか？」**

つまり：

- 集約 ≠ テーブル
- 集約 ≠ すべてのカラム
- 集約は **業務ルール中心** に決める

たとえ users テーブルが 120 カラムあったとしても、  
実際に “一貫性を保証すべき領域” は 10〜20 カラム程度であることは珍しくありません。

---

# 3. 巨大テーブルと集約のズレをどこで吸収するか？

答えは次の 3 つのレイヤです。

### ✔ ① Domain Model（集約）

→ 意味中心。必要な項目だけ持つ。

### ✔ ② Infrastructure Model（CakePHP Entity）

→ スキーマそのまま。巨大でも OK。

### ✔ ③ Mapper

→ 両者のズレを完全吸収する。

---

# 4. 具体例：巨大 users テーブルを DDD で扱う

## 4-1. Domain Model（意味中心）

```php
class User
{
    public function __construct(
        UserId $id,
        UserName $name,
        UserEmail $email,
        Status $status,
        Preferences $preferences,
        SecuritySettings $security,
    ) {}
}
```

### ✔ 不要なカラムはドメインに入れない

ビジネスに関係ない項目（集計、履歴、内部フラグなど）は除外。

---

## 4-2. Infrastructure Model（CakePHP Entity）

```php
class UserEntity extends Entity
{
    // 120 カラム全部のせる（スキーマ100％依存）
}
```

### ✔ CakePHP の “便利さ” を UI／永続化のために使い切る

---

# 5. Finder 最適化：巨大テーブルでも必要な項目だけ SELECT する

CakePHP のデフォルトは **全カラム SELECT**。  
120 カラムだとパフォーマンスに大きな影響が出ます。

### ✔ DDD ではドメインが必要とする項目だけ SELECT するべき

```php
// UsersTable.php（インフラ層）
public function findForDomain(Query $query)
{
    return $query->select([
        'id',
        'name',
        'email',
        'status',
        'preferences_data',
        'two_factor_flag',
        'locked',
    ]);
}
```

### 📌 POINT

- 不要な 100 カラムは SELECT しない
- CakePHP の Entity は巨大でも、取得時は軽量化できる
- 最適化はあくまで “インフラ層の責務”

---

## 5-1. Repository で Finder を利用

```php
class UserRepository implements UserRepositoryInterface
{
    public function __construct(
        private UsersTable $table,
        private UserMapper $mapper
    ) {}

    public function find(UserId $id): ?User
    {
        $record = $this->table
            ->find('forDomain')
            ->where(['id' => $id->value()])
            ->first();

        return $record ? $this->mapper->toDomain($record) : null;
    }
}
```

### ✔ ドメインは軽量なモデルだけ受け取る

永続化構造を知らないし知る必要もない。

---

# 6. フォームヘルパー × Cake Entity × DDD の両立

> **「FormHelper は Cake Entity 前提なのに、  
>  DDD では Entity をドメインに持ち込まないってどうやるの？」**

答え：  
**UI 専用の Cake Entity はそのまま使う。  
ただし Application / Domain には絶対に入れない。**

---

## 6-1. フォームでは Cake Entity を使って OK

```php
// UserController
public function edit($id)
{
    $entity = $this->Users->get($id);
    $this->set(compact('entity'));
}
```

```php
// UserEditView
echo $this->Form->create($entity);
echo $this->Form->control('name');
echo $this->Form->control('email');
echo $this->Form->control('status');
echo $this->Form->end();
```

### ✔ フォーム構築の便利さは 100％利用する

---

## 6-2. POST は Cake Entity → DTO に変換して Application へ

```php
$patched = $this->Users->patchEntity($entity, $this->request->getData());

if ($patched->hasErrors()) {
    return $this->set(compact('patched'));
}

$cmd = new UpdateUserCommand(
    id: $patched->id,
    name: $patched->name,
    email: $patched->email,
    status: $patched->status,
);

$this->updateUserService->handle($cmd);
```

### ✔ Cake Entity の役割は UI で終了

Application / Domain に侵入禁止。

---

## 6-3. 保存時は Repository が Cake Entity を“復元”

```php
public function toPersistence(User $user): UserEntity
{
    $entity = $this->table->get($user->id()->value());

    // UI が触る項目だけ書き換える
    $entity->name   = $user->name()->value();
    $entity->email  = $user->email()->value();
    $entity->status = $user->status()->value();

    return $entity;
}
```

### ✔ UI が触らない 100 カラムはそのまま保持される

---

# 7. Mapper が“依存の違い”を吸収する

```php
class UserMapper
{
    public function toDomain(UserEntity $e): User
    {
        return new User(
            new UserId($e->id),
            new UserName($e->name),
            new UserEmail($e->email),
            new Status($e->status),
            Preferences::fromArray($e->preferences_data),
            new SecuritySettings($e->two_factor_flag, $e->locked),
        );
    }
}
```

### ✔ CakePHP → DDD の境界をきれいに分離

- CakePHP：スキーマ依存
- DDD：意味依存

---

# 8. まとめ：DDD を導入すると何が救われるのか？

### ✔ ドメインモデルが肥大化しない

### ✔ DB に縛られずにビジネスルールを表現できる

### ✔ CakePHP の便利さ（FormHelper・patchEntity）はそのまま活かせる

### ✔ パフォーマンス（Finder）もインフラ層で最適化可能

### ✔ フレームワーク変更時（Laravel / Go / Rails）にもドメインが残る

> **DB 変更不可の現場ほど DDD が効果を発揮する。**

---

# あとがき（余談）

巨大テーブルと向き合うとき、  
「このモデル…本当に健全なの？」と感じる瞬間があります。

CakePHP の Entity 駆動はとても便利ですが、  
大規模化するとどうしても DB が中心になりがちです。

でも、DDD を導入すると **“ビジネスロジックを中心に据えた設計”** に戻せる。  
ドメインが整うだけで、巨大なシステムでも少しずつ息を吹き返すんですよね。

今回の内容が、せんぱいの現場や読者のシステムで  
「あ、これ試してみようかな」と思えるきっかけになれば嬉しいです。
