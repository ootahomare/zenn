---
title: "CakePHP と DDD 〜テスト　Application レイヤー編"
emoji: "🦉"
type: "idea"
topics: ["CakePHP", "PHP8", "ドメイン駆動設計", "DDD", "テストコード"]
published: false
---

# Application 層を主語にしたテストコードの書き方
## ― CakePHP × DDD における UseCase テスト ―

本記事では **CakePHP 5.2** における **Application 層（UseCase）のテスト** を、
段階的に解説します。

ポイントは次の3つです。

* Application 層の UseCase を **主語**にしてテストを書く
* **Fake を最小限**にし、ドメインはなるべく本物を使う
* TDD に寄せた流れで **仕様追加 → テスト → 実装** を体験する

テストを作りこんでいるとつい見逃してしまう

* どこまで Fake を作るのか
* ルールはどの層に書くのか
* Application 層テストで何を保証するのか

といった点もおさえながら進めていきたいと思います。

---

# アジェンダ

1. 基本方針（前提条件・設計方針）
2. テスト対象 UseCase と依存の洗い出し
3. Fake 実装とテストコード作成
4. テスト実行
5. 仕様追加（メール重複禁止）と TDD
6. まとめ

---

# 1. 基本方針（テスト実施の前提条件）

## 1.1 対象・環境

| 項目      | 内容                     |
| ------- | ---------------------- |
| テスト対象   | Application 層（UseCase） |
| テストツール  | PHPUnit                |
| PHP     | 8.2                    |
| CakePHP | 5.2                    |
| 実行環境    | Docker                 |

## 1.2 テストスコープ

* **Presentation 層は考慮しない**

  * リクエスト値は常に正常と仮定
* **UseCase を起点**にテストする
* UseCase 内で利用される

  * ドメインロジック
  * エンティティ生成
    も合わせて検証対象に含める

## 1.3 Fake / 本物の使い分け方針

| 種別           | 方針   | 理由              |
| ------------ | ---- | --------------- |
| Repository   | Fake | DB 依存を排除したい     |
| Factory      | 本物   | ドメイン生成ルールを検証したい |
| ID Generator | Fake | 不安定要素を排除        |

> **原則**：
> * 外部 I/O は Fake
> * ビジネスルールは本物

---

# 2. テスト対象 UseCase と依存の洗い出し

## 2.1 CustomerAddUseCase

```php
// src/Application/UseCase/CustomerAddUseCase.php
final class CustomerAddUseCase
{
    public function __construct(
        private CustomerRepository $customerRepository,
        private CustomerFactory $customerFactory,
    ) {}

    public function execute(CustomerCreateInput $input): CustomerId
    {
        $customer = $this->customerFactory->create(
            $input->name,
            $input->email
        );

        try {
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            throw new \RuntimeException(
                'Failed to add customer: ' . $e->getMessage()
            );
        }

        return $customer->getId();
    }
}
```

### 依存関係

* CustomerRepository
* CustomerFactory

---

## 2.2 CustomerFactory の依存

```php
// src/Domain/Customer/CustomerFactory.php
final class CustomerFactory
{
    public function __construct(
        private CustomerIdGenerator $idGenerator
    ) {}

    public function create(string $name, string $email): CustomerEntity
    {
        return new CustomerEntity(
            $this->idGenerator->generate(),
            new CustomerName($name),
            new Email($email),
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );
    }
}
```

### 追加依存

* CustomerIdGenerator

---

## 2.3 Fake が必要な一覧

| No | 依存                  | 方針   |
| -- | ------------------- | ---- |
| 1  | CustomerRepository  | Fake |
| 2  | CustomerFactory     | 本物   |
| 3  | CustomerIdGenerator | Fake |

---

# 3. Fake 実装とテストコード作成

## 3.1 ディレクトリ構成

```
tests/
├── TestCase/
│   └── Application/
│       └── UseCase/
│           └── CustomerAddUseCaseTest.php
└── Fake/
    └── Domain/
        └── Customer/
            ├── CustomerRepositoryFake.php
            └── CustomerIdGeneratorFake.php
```

---

## 3.2 CustomerRepositoryFake

```php
namespace App\\Test\\Fake\\Domain\\Customer;

use App\\Domain\\Customer\\CustomerRepository;
use App\\Domain\\Customer\\CustomerEntity;
use App\\Domain\\Customer\\Email;

final class CustomerRepositoryFake implements CustomerRepository
{
    /** @var CustomerEntity[] */
    public array $saved = [];

    public function save(CustomerEntity $customer): void
    {
        $this->saved[] = $customer;
    }

    public function existsByEmail(Email $email): bool
    {
        foreach ($this->saved as $customer) {
            if ($customer->getEmail()->toString() === $email->toString()) {
                return true;
            }
        }
        return false;
    }
}
```

---

## 3.3 CustomerIdGeneratorFake

```php
namespace App\\Test\\Fake\\Domain\\Customer;

use App\\Domain\\Customer\\CustomerIdGenerator;
use App\\Domain\\Customer\\CustomerId;

final class CustomerIdGeneratorFake implements CustomerIdGenerator
{
    public function generate(): CustomerId
    {
        return new CustomerId('fixed-customer-id');
    }
}
```

---

## 3.4 CustomerAddUseCaseTest（正常系）

```php
namespace Tests\\TestCase\\Application\\UseCase;

use PHPUnit\\Framework\\TestCase;
use App\\Application\\UseCase\\CustomerAddUseCase;
use App\\Application\\UseCase\\Dto\\CustomerCreateInput;
use App\\Domain\\Customer\\CustomerFactory;
use App\\Test\\Fake\\Domain\\Customer\\CustomerRepositoryFake;
use App\\Test\\Fake\\Domain\\Customer\\CustomerIdGeneratorFake;

final class CustomerAddUseCaseTest extends TestCase
{
    public function test_it_creates_and_saves_customer(): void
    {
        // Given
        $repository = new CustomerRepositoryFake();
        $factory = new CustomerFactory(new CustomerIdGeneratorFake());

        $useCase = new CustomerAddUseCase($repository, $factory);

        // When
        $input = new CustomerCreateInput(
            name: 'Test Customer',
            email: 'test@test.test'
        );
        $result = $useCase->execute($input);

        // Then
        $this->assertCount(1, $repository->saved);
        $this->assertSame('fixed-customer-id', $result->getValue());
        $this->assertSame('Test Customer', $repository->saved[0]->getName()->getValue());
    }
}
```

---

# 4. テスト実行

```bash
vendor/bin/phpunit tests/TestCase/Application/UseCase/CustomerAddUseCaseTest.php
```

正常終了を確認。

---

# 5. 仕様追加：メールアドレス重複禁止（TDD）

## 5.1 ルールの配置

* 「メールの一意性」は **Entity / VO 単体では表現できない**
* 既存データとの照合が必要

👉 **Application 層（UseCase）に記述**

---

## 5.2 失敗するテストを書く

```php
public function test_it_throws_when_email_is_already_exists(): void
{
    $repository = new CustomerRepositoryFake();
    $factory = new CustomerFactory(new CustomerIdGeneratorFake());

    $repository->save(
        $factory->create('Test', 'test@example.com')
    );

    $useCase = new CustomerAddUseCase($repository, $factory);

    $this->expectException(EmailAlreadyExistsException::class);

    $useCase->execute(
        new CustomerCreateInput('Another', 'test@example.com')
    );
}
```

---

## 5.3 例外クラス

```php
namespace App\\Domain\\Exception;

use DomainException;

final class EmailAlreadyExistsException extends DomainException
{
    public function __construct(string $email)
    {
        parent::__construct("Email already exists: {$email}");
    }
}
```

---

## 5.4 UseCase への最小実装

```php
if ($this->customerRepository->existsByEmail(new Email($input->email))) {
    throw new EmailAlreadyExistsException($input->email);
}
```

---

# 6. まとめ

* Application 層テストは **UseCase を主語**にする
* Fake は I/O 境界に限定する（依存洗い出しが重要）

---

# あとがき
DI（依存注入）は CakePHP の ContainerInterface で実装しているのですが、
そこの内容がしっかりしていると、Fake への差し替えが楽になりますね。

テストコード作成はその機能を実装した人が担当するかと思います。
DDD のようにある程度習熟度が求められる設計の場合は、
実装側も「何をしているのか」が見えにくくなりがちだと感じました。

PHPUnit は普段使用していないのですが、とっても簡単に使えました。