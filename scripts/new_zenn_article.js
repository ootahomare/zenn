// scripts/new-zenn-article.js
import fs from "fs";
import { randomBytes } from "crypto";

const slug = "article-" + randomBytes(8).toString("hex");
const path = `articles/${slug}.md`;

const content = `---
title: ""
emoji: "🦉"
type: "idea"
topics: ["CakePHP4", "PHP8", "ドメイン駆動設計", "DDD"]
published: false
---

`;

fs.writeFileSync(path, content);
console.log(`Created: ${path}`);
