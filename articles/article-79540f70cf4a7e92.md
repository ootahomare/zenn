---
title: "CakePHP と DDD 〜テスト　Application レイヤー編"
emoji: "🦉"
type: "idea"
topics: ["CakePHP4", "PHP8", "ドメイン駆動設計", "DDD", "テストコード"]
published: false
---

# 0. はじめに

CakePHP の Test を使用して、テストを実施していきたいと思います。
各レイヤーごとに実施していきます。
今回は Application 層になります。

# アジェンダ

1. 基本方針
2. テストコード作成
3. テストの実施
4. バリデーション追加・テストの実施
5. まとめ

# 1. 基本方針

| テスト対象     | ツール  | PHP ver. | CakePHP ver. | 実行環境 |
| -------------- | ------- | -------- | ------------ | -------- |
| Application 層 | PHPUnit | 8.2      | 5.2          | Docker   |

UseCase のテストを通してドメイン層のテストも行います。
リクエストの内容は正常とします。(Presentation 層は考慮しない)

# 2. テストコード作成

## 2.1. 　テスト対象 UseCase の依存の洗い出し

UseCase 実現のために依存しているインターフェースを全て洗い出します。

```php
// src/Application/CustomerAddUseCase
class CustomerAddUseCase
{
    public function __construct(
        private CustomerRepository $customerRepository,
        private CustomerFactory $customerFactory,
    ) {}

    public function execute(CustomerCreateRequest $request): CustomerId
    {
        $customer = $this->customerFactory->create(
            $request->getName(),
            $request->getEmail()
        );
        try {
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to add customer: ' . $e->getMessage());
        }

        return $customer->getId();
    }
}
```

ここでは以下２つが依存している

- CustomerRepository
- CustomerFactory

```php
// src/Domain/Customer/CustomerFactory
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
            new \DateTime(),
            new \DateTime()
        );
    }
}
```

CustomerFactory でも１つ依存している

- CustomerIdGenerator

まとめると必要な Fake の個数が判明する

| No. | 依存 class          | 方針                       |
| --- | ------------------- | -------------------------- |
| 1   | CustomerRepository  | Fake                       |
| 2   | CustomerFactory     | 本物（ドメイン層のテスト） |
| 3   | CustomerIdGenerator | Fake                       |

## ２.2. ディレクトリ作成

以下のような構成でテストコードを作成します。

```
tests/
├── TestCase/
│   └── Application/
│       └── UseCase/
│           └── CustomerAddUseCaseTest.
php
│
└── Fake/
    └── Domain/
        └── Customer/
        　   ├── CustomerRepositoryFake.php
        　   └── CustomerIdGeneratorFake.php

```

## 2.3. Fake repository の作成

CustomerEntity を配列に格納するだけにします。
名前空間は App\ になります。

```php
namespace App\Test\Fake\Domain\Customer;

use App\Domain\Customer\CustomerRepository;
use App\Domain\Customer\CustomerEntity;

final class CustomerRepositoryFake implements CustomerRepository
{
    /** @var CustomerEntity[] */
    public array $saved = [];

    public function save(CustomerEntity $customer): void
    {
        $this->saved[] = $customer;
    }
}
```

## 2.4. CustomerIdGeneratorFake の作成

ID は infrastructure 層にて Uuid で生成しています。
Fake では適当な文字列で VO を生成します。

```php
namespace App\Test\Fake\Domain\Customer;

use App\Domain\Customer\CustomerIdGenerator;
use App\Domain\Customer\CustomerId;

final class CustomerIdGeneratorFake implements CustomerIdGenerator
{
    public function generate(): CustomerId
    {
        return new CustomerId('fixed-customer-id');
    }
}
```

## 2.5. CustomerAddUseCaseTest の作成

以下のポイントを押さえます。

- TestCase クラスを継承すること
- ファクトリは本物を使用すること
- アサーションで期待値を記述すること

```php
namespace Tests\TestCase\Application\UseCase;

use PHPUnit\Framework\TestCase;
use App\Application\UseCase\CustomerAddUseCase;
use App\Application\UseCase\Dto\CustomerCreateInput;
use App\Domain\Customer\CustomerFactory;
use App\Test\Fake\Domain\Customer\CustomerRepositoryFake;
use App\Test\Fake\Domain\Customer\CustomerIdGeneratorFake;

final class CustomerAddUseCaseTest extends TestCase
{
    public function test_it_creates_and_saves_customer(): void
    {
        // Given
        $repository = new CustomerRepositoryFake();
        $idGenerator = new CustomerIdGeneratorFake();
        $factory = new CustomerFactory($idGenerator);

        $useCase = new CustomerAddUseCase(
            $repository,
            $factory
        );

        // When
        $input = new CustomerCreateInput(
            name: 'Test Customer',
            email: 'test@test.test'
        );
        $useCase->execute($input);

        // Then
        $this->assertCount(1, $repository->saved);

        $savedCustomer = $repository->saved[0];
        $this->assertSame('fixed-customer-id', $savedCustomer->getId()->getValue());
        $this->assertSame('Test Customer', $savedCustomer->getName()->getValue());
    }
}
```

# 3. テストの実施

Docker コンテナで以下のコマンドを実行します。

```bash
vendor/bin/phpunit tests/TestCase/Application/UseCase/CustomerAddUseCaseTest.php
```

正常終了

```bash
root@13e658e7e88b:/var/www/html# vendor/bin/phpunit tests/TestCase/Application/UseCase/CustomerAddUseCaseTest.php
PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.30
Configuration: /var/www/html/phpunit.xml.dist

.                                                                   1 / 1 (100%)

Time: 00:00.005, Memory: 14.00 MB

OK (1 test, 3 assertions)
```

# 4. バリデーション追加・テストの実施

TDD でバリデーションを追加してみましょう。

## 4.1. メールアドレスで異常系を発生させてみる

メールアドレスを空文字にしてテスト実行します。

```diff
$input = new CustomerCreateInput(
            name: 'Test Customer',
-            email: 'test@test.test'
+            email: ''
        );
        $useCase->execute($input);
```

### 結果

エラーが発生

```bash
root@13e658e7e88b:/var/www/html# vendor/bin/phpunit tests/TestCase/Application/UseCase/CustomerAddUseCaseTest.php
PHPUnit 11.5.46 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.30
Configuration: /var/www/html/phpunit.xml.dist

E                                                                   1 / 1 (100%)

Time: 00:00.012, Memory: 14.00 MB

There was 1 error:

1) Tests\TestCase\Application\UseCase\CustomerAddUseCaseTest::test_it_creates_and_saves_customer
InvalidArgumentException: Invalid email address:

/var/www/html/src/Domain/ValueObject/Email.php:12
/var/www/html/src/Domain/Customer/CustomerFactory.php:18
/var/www/html/src/Application/UseCase/CustomerAddUseCase.php:19
/var/www/html/tests/TestCase/Application/UseCase/CustomerAddUseCaseTest.php:31

ERRORS!
Tests: 1, Assertions: 0, Errors: 1.
```

# 5. まとめ
