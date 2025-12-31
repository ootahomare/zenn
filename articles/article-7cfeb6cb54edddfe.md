---
title: "CakePHP と DDD ～依存注入について～"
emoji: "🦉"
type: "idea"
topics: ["CakePHP", "PHP8", "ドメイン駆動設計", "DDD", "DI"]
published: false
---

# CakePHP の DI（依存注入）について
## ― ContainerInterface の利用 ―

今回は CakePHP × DDD の生命線ともいえる **DI（依存注入）** について、
実装方法の確認と設計上の意図を整理していきます。

これまでの記事では、

- 集約境界をどう設計するか
- Application 層（UseCase）のテストをどう書くか

といった内容を扱ってきました。

これらを成立させる前提として共通しているのが、
**「実装を直接 new しない構造」＝ DI が機能していること**です。

本記事では、CakePHP における DI の実現方法を整理しながら、

- なぜ DI が必要なのか
- なぜ ContainerInterface を使うのか
- なぜ Service Provider を分けたほうがよいのか

といった点を確認していきます。

---

# アジェンダ

1. DI （依存注入）とは？
2. CakePHP における DI の実現方法
3. Service Provider を切り分けて、管理を楽に
4. 実装を差し替える場合は？
5. まとめ

---

# 1. DI（依存注入）とは？

DI（Dependency Injection）とは、

> クラス自身が依存オブジェクトを生成せず、
> 外部から注入されることで依存関係を解決する設計

のことです。

DI を使わない場合、クラスは自分で依存先を決めてしまいがちです。

```php
final class CustomerAddUseCase
{
    private CustomerRepository $repository;

    public function __construct()
    {
        $this->repository = new CustomerRepositoryImpl();
    }
}
```
このような構造では、

- 実装が固定される
- テスト時に Fake を差し替えられない

といった問題が起きやすくなります。

また上記の場合、アプリケーション層にインフラ層の実装が漏れており、**下位モジュールに依存した状態**です。

これは**依存性逆転の原則（DIP）** に反しており、
DDD のレイヤード構造を崩す原因になります。

---

DI を使うと、依存関係はコンストラクタで明示されます。

```php
final class CustomerAddUseCase
{
    public function __construct(
        private CustomerRepository $repository
    ) {}
}
```

これにより、

- 何に依存しているかが一目で分かる
- テスト時に Fake を注入できる
- レイヤー間の責務が明確になる

といったメリットが得られます。

あとはこのコンストラクタにどうやって実装を渡すのか・・・

---

UseCase を呼び出す Presentation 層の Controller はどうなっているでしょうか。

UseCase を組み立てるには CustomerRepository の実装が必要です。
つまり以下のような記述が必要になります。

```php
new CustomerAddUseCase(
    new CustomerRepository()
);
```
しかしこれを Controller に書くと、前述した**実装の固定**が発生します。

Controller は「UseCase を呼ぶ」責務に集中し、
**依存解決はフレームワークに委ねる**のが理想です。

---

# 2. CakePHP における DI の実現方法

CakePHP では、
**ContainerInterface** を利用して DI を実現します。

主に利用するのは以下の仕組みです。

- ContainerInterface
- Service Provider（サービス登録）

---

## 2.1 ContainerInterface への登録

CakePHP では Application クラスを通して、
DI コンテナに「どのインターフェースに、どの実装を渡すか」を登録します。

```php
// src/Application.php
use Cake\Core\ContainerInterface;
use Cake\Http\BaseApplication;

final class Application extends BaseApplication
{
    public function services(ContainerInterface $container): void
    {
        $container->add(
            CustomerRepository::class, // interface
            CustomerRepositoryImpl::class // implement
        );
    }
}
```

これにより、

- インターフェースを要求された場合に
- 対応する実装が**自動で解決される**

という仕組みが作られます。

---

## 2.2 コンストラクタインジェクションとの関係

UseCase 側では、
**インターフェースのみを依存として宣言**します。

```php
final class CustomerAddUseCase
{
    public function __construct(
        private CustomerRepository $repository
    ) {}

    public function execute(CustomerCreateInput $input): void
    {
        // ユースケースの処理
    }
}
```
インターフェースを宣言するだけで、ContainerInterface を参照し、対応する実装をコンストラクタに自動で渡してくれます。

---

# 3. Service Provider を切り分けて、管理を楽に

Application クラスにすべての DI 定義を書いていくと、
定義が肥大化し、見通しが悪くなりがちです。

そこで、**Service Provider を用途・ドメイン単位で分ける**
構成を取ります。

---

## 3.1 Service Provider の役割

Service Provider では、
特定のドメインや機能に関する DI 定義をまとめます。

```php
// src/ServiceProvider/CustomerServiceProvider.php
use Cake\Core\ContainerInterface;
use Cake\Core\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    protected array $provides = [
        CustomerAddUseCase::class,
        CustomerRepository::class,
    ];

    public function services(ContainerInterface $container): void
    {
        $container->add(
            CustomerRepository::class, // interface
            CakeCustomerRepository::class // implement
        );

        $container->add(
            CustomerIdGenerator::class, // interface
            UuidCustomerIdGenerator::class // implement
        );

        $container->add(CustomerFactory::class) // constructor
            ->addArgument(CustomerIdGenerator::class); // argument
        
        $container->add(CustomerAddUseCase::class) // constructor
            ->addArgument(CustomerRepository::class) // argument
            ->addArgument(CustomerFactory::class); // argument
    }
}
```
UseCase・Factory・Repository をまとめて定義することで、
**「このユースケースを成立させる依存関係」** が一目で分かります。


Application 側では、Provider を呼び出すだけにします。

```php
// src/Application.php
public function services(ContainerInterface $container): void
{
    $container->addServiceProvider(
        new CustomerServiceProvider()
    );
}
```

---

### Provider を分けるメリット

- ドメイン単位で依存関係を把握しやすい
- 不要になった定義を削除しやすい
- DDD のモジュール構成と相性がよい

DI 定義そのものも「設計の一部」として扱えるようになります。

---

# 4. 実装を差し替える場合は？

DI の価値が最も分かりやすく出るのが、
**実装を差し替えたい場面**です。

---

## 4.1 テスト時に Fake を注入する

テストでは、Repository や外部依存を Fake に差し替えます。

```php
$container->add(
    CustomerRepository::class,
    CustomerRepositoryFake::class
);
```

UseCase 側のコードは一切変更せずに、
テスト専用の構成を作ることができます。

---

## 4.2 実装変更への耐性

例えば、

- DB 実装を変更する
- ORM を差し替える
- 外部 API に移行する

といった変更があっても、

- Infrastructure 実装
- DI 定義

のみを修正すれば済みます。

DI が正しく機能していれば、
**変更の影響範囲を最小限に抑えることができます。**

---

# 5. まとめ

- DI は DDD を成立させるための前提条件
- CakePHP では ContainerInterface を使って DI を実現する
- Service Provider を分けることで管理が楽になる
- 実装の差し替え・テストが容易になる

Application.php services() に直書きするとかなりのコード量になりますが、UseCase 単位で Service Provider を切り分けると一気に見通しが良くなります。

---

## あとがき

DI を実装すると interface を使用してきた甲斐を感じるのではないでしょうか。

DI の調整は、現状の依存関係を整理する時間となります。
一度実装したソースコードでも「ここはインターフェース切ったほうがいいな」といった新たな気づきがあったりします。

レビュワーからすると依存性逆転が実現しているか一目でわかるので、恩恵は大きいですよね。
