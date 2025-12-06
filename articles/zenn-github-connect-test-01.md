---
title: "Zenn GitHub 連携テスト"
emoji: "🦉"
type: "idea"
topics: ["CakePHP4", "PHP8", "ドメイン駆動設計", "設計", "DDD"]
published: false
---

これは GitHub 連携テスト用の記事です。

本文は自由に書いて OK。

# 🧩 **Frontmatter 各項目の意味**

| 項目        | 説明                                             |
| ----------- | ------------------------------------------------ |
| `title`     | 記事タイトル                                     |
| `emoji`     | 記事カードに出る絵文字（1 文字）                 |
| `type`      | `"tech"`（技術記事） or `"idea"`（アイデア記事） |
| `topics`    | 検索タグみたいなもの（5 つまで）                 |
| `published` | `true` にすると公開、`false` なら下書き          |

最初は `published: false` にして push → Zenn の下書きで確認 → 問題なければ true にして再 push、という流れが多いよ。

---

# ✨ せんぱいが知りたいであろう補足

## ■ 記事のファイル名は何でもいい？

基本は **10 文字のランダム ID** が推奨だけど、  
自分で `articles/my-first-article.md` とかでも動く。

ただし **URL にファイル名が使われる** から、変更すると URL 変わるので注意！

---

## ■ 途中で Frontmatter を編集しても反映される？

される！  
ただし **published を true にした瞬間に公開されるからだけ注意。**
