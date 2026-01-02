---
title: "CakePHP と DDD 〜テスト　Infrastructure レイヤー編"
emoji: "🦉"
type: "idea"
topics: ["CakePHP", "PHP8", "ドメイン駆動設計", "DDD"]
published: false
---

# Infrastructure 層を主語にしたテストコードの書き方

## ― CakePHP × DDD における CustomerRepository テスト ―

本記事では、これまで扱ってきた **Customer ドメイン**を題材に、  
**Infrastructure 層（Repository 実装）を主語にしたテスト**の書き方を整理します。

DDD を採用すると、Application / Domain のテストは比較的書きやすい一方で、

> Infrastructure 層はどうテストするのが正解なのか？

という疑問に直面しがちです。

ここでは **CakeCustomerRepository（本番実装）をテスト用 DB で動かす**という前提で、
Repository 実装テストの考え方と具体例を示します。

---

# アジェンダ

1. 基本方針（前提条件・設計方針）
2. Customer ドメインと Repository の位置付け
3. CakeCustomerRepository の実装
4. Repository テストコード
5. 仕様追加（メール重複禁止）と TDD
6. まとめ

---

# 1. 基本方針（テスト実施の前提条件）

## 1.1 対象・環境

| 項目         | 内容                                        |
| ------------ | ------------------------------------------- |
| テスト対象   | Infrastructure 層（CakeCustomerRepository） |
| テストツール | PHPUnit                                     |
| PHP          | 8.2                                         |
| CakePHP      | 5.2                                         |
| 実行環境     | Docker                                      |

- Application 層はテスト対象外
- Repository 実装を **主語** にして検証する

---

## 1.2 ディレクトリ構成（Customer 版）

```
src/
├─ Domain/
│  └─ Customer/
│     ├─ Entity/Customer.php
│     ├─ Repository/CustomerRepositoryInterface.php
│     └─ ValueObject/Email.php
├─ Application/
│  └─ Customer/
│     └─ RegisterCustomerUseCase.php
└─ Infrastructure/
   └─ Persistence/
      └─ CakePHP/
         └─ CakeCustomerRepository.php
```

---

# 2. CustomerRepository の責務

## 2.1 Repository Interface（Domain）

```php
interface CustomerRepositoryInterface
{
    public function save(Customer $customer): void;
}
```

---

# 3. CakeCustomerRepository 実装（Infrastructure）

```php
class CakeCustomerRepository implements CustomerRepository
{
    private $customersTable;

    public function __construct()
    {
        $this->customersTable = TableRegistry::getTableLocator()->get('Customers');
    }

    public function save(CustomerEntity $customer): void
    {
        $entity = $this->customersTable->newEntity([
            'id' => $customer->getId()->getValue(),
            'name' => $customer->getName()->getValue(),
            'email' => $customer->getEmail()->toString(),
            'created' => $customer->getCreated(),
            'modified' => $customer->getModified(),
        ]);

        $this->customersTable->saveOrFail($entity);
    }
}
```

---

# 4. CakeCustomerRepository テスト

```php
final class CakeCustomerRepositoryTest extends TestCase
{
    private CakeCustomerRepository $repository;
    private CustomersTable $customers;

    protected function setUp(): void
    {
        parent::setUp();

        // テスト用の CustomersTable
        $this->customers = TableRegistry::getTableLocator()->get('Customers');

        // テスト前に初期化（truncate 相当）
        $this->customers->deleteAll([]);

        // Repository は本番実装
        $this->repository = new CakeCustomerRepository();
    }

    public function test_save_customer_success(): void
    {
        $customer = CustomerEntity::create(
            new CustomerId('c-001'),
            new CustomerName('テスト顧客'),
            new Email('test@example.com'),
            new FrozenTime('2025-01-01 10:00:00'),
            new FrozenTime('2025-01-01 10:00:00'),
        );

        $this->repository->save($customer);

        $saved = $this->customers->find()->first();

        $this->assertNotNull($saved);
        $this->assertSame('c-001', $saved->id);
        $this->assertSame('テスト顧客', $saved->name);
        $this->assertSame('test@example.com', $saved->email);
    }
}
```

---

# 5. 仕様追加（メール重複禁止）と TDD

```php
public function test_duplicate_email_is_not_allowed(): void
{
    $customer1 = Customer::create(new Email('dup@example.com'));
    $customer2 = Customer::create(new Email('dup@example.com'));

    $this->repository->save($customer1);

    $this->expectException(RuntimeException::class);
    $this->repository->save($customer2);
}
```

---

# 6. まとめ

- Infrastructure 層の Repository は **本番実装をテストする**
- DB / ORM を含めて振る舞いを保証する
- Repository テストは責務の境界を明確にする
