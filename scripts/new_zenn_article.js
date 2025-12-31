// scripts/new-zenn-article.js
import fs from "fs";
import path from "path";
import { randomBytes } from "crypto";

// ---------------------------
// slug を生成する関数
// ---------------------------
function generateSlug() {
  return `article-${randomBytes(8).toString("hex")}`;
}

// ---------------------------
// 記事テンプレート
// ---------------------------
function createArticleContent() {
  return `---
title: ""
emoji: "🦉"
type: "idea"
topics: ["CakePHP", "PHP8", "ドメイン駆動設計", "DDD"]
published: false
---

`;
}

// ---------------------------
// メイン処理
// ---------------------------
function createZennArticle() {
  try {
    const slug = generateSlug();
    const articlesDir = path.resolve("articles");
    const filePath = path.join(articlesDir, `${slug}.md`);

    // ディレクトリがなければ作成
    if (!fs.existsSync(articlesDir)) {
      fs.mkdirSync(articlesDir, { recursive: true });
    }

    const content = createArticleContent();
    fs.writeFileSync(filePath, content, "utf8");

    console.log(`✅ Created: ${filePath}`);
  } catch (err) {
    console.error("❌ Error creating article:", err);
  }
}

// スクリプト実行
createZennArticle();
