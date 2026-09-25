# phptpl —— 模板占位符渲染库

PHP 8 的小型模板渲染库：把 `{{ ... }}` 占位符、`{{{ ... }}}` 原样输出与
`{% include "片段" %}` 片段引用渲染成字符串。纯 PHP 标准库（`php-cli`），
无第三方依赖，不用 Composer，**不使用 mbstring**。

```php
use Phptpl\Engine;

$engine = new Engine(__DIR__ . '/templates');   // 缺失变量策略默认 throw
echo $engine->render('page.tpl', ['title' => 'Home', 'site' => 'phptpl']);
echo $engine->renderString('Hello, {{ user.name }}!', ['user' => ['name' => 'Ada']]);
```

## 目录

```
src/            实现（Engine.php / Parser.php / Escaper.php / TplError.php）
templates/      模板样例（hello.tpl / page.tpl / card.tpl + snippets/footer.tpl）
tests/run.php   既有用例（13 个）
repro.php       调用方给的复现脚本
check/check.php 固定验收入口（**勿改**）
scripts/check.sh 固定验收入口的薄封装
```

## 对外保证（8 条）

下面每一条都是对外保证。**验收以 `check/check.php` 为准**，语义细节以本节为准。

1. **占位符路径**：`{{ path.to.key }}` 支持嵌套数组路径，也支持**数字下标**
   （`{{ list.0 }}`、`{{ rows.1.name }}`）；末段可以是内置 **`length`** ——
   作用是数组元素个数 / 字符串字节长度（`{{ items.length }}`、`{{ word.length }}`）。
   占位符名两侧的空白忽略（`{{  name  }}` 等价于 `{{ name }}`）。
2. **默认转义**：普通占位符的输出按 `ENT_QUOTES | ENT_SUBSTITUTE` + UTF-8 处理 ——
   `& < > " '` 一律转义（`'` 转成 `&#039;`），非法 UTF-8 序列用替换字符 `U+FFFD`
   顶掉而不是整段丢弃。`{{{ raw }}}`（三花括号）输出**不转义**。
3. **缺失变量策略**：由构造参数 `$missingPolicy` 决定，三选一，默认 `throw`：
   - `Engine::MISSING_THROW`（`'throw'`）：取不到变量时抛 `TplError`（`kind = 'missing'`，带行号）；
   - `Engine::MISSING_EMPTY`（`'empty'`）：渲染成空串；
   - `Engine::MISSING_KEEP`（`'keep'`）：把该占位符原文（含花括号）原样留在输出里。
4. **片段引用**：`{% include "片段名" %}` 按**模板目录**相对路径解析（允许子目录，
   如 `{% include "snippets/footer.tpl" %}`）。include 嵌套深度上限为 `maxDepth`
   （构造参数，默认 8）：**顶层模板深度为 0，每次 include 令深度 +1**；当某次 include
   之后要渲染的片段深度超过 `maxDepth` 时，抛 `TplError`（`kind = 'depth'`）。
   片段不存在抛 `TplError`（`kind = 'include'`）。
5. **循环 include**：A → B → A 这类环必须被**检测**并抛 `TplError`（`kind = 'cycle'`），
   不许无限递归、不许耗尽内存。
6. **不执行任意 PHP**：模板内容只当文本与占位符处理 —— 不得 `eval` 模板或变量，
   不得把用户数据当作 `include` 的目标。实现里不允许出现 `eval`。
7. **逐字节确定**：渲染结果只由模板源码与变量决定 —— 空行保留、行尾空白与缩进原样保留
   （**不做 trim**）、不重排行、不追加尾随换行；同一输入两次渲染结果逐字节一致。
8. **语法错误带行号**：`{{` 未闭合、`{%` 未闭合、空占位符、未知过滤器（如 `{{ a | upper }}`）、
   未知标签都抛 `TplError`，且 `kind = 'syntax'`、`line` 为出错位置的行号（从 1 开始）。

### 公开接口

```php
final class Engine
{
    public const MISSING_THROW = 'throw';
    public const MISSING_EMPTY = 'empty';
    public const MISSING_KEEP  = 'keep';

    public function __construct(string $templateDir, string $missingPolicy = self::MISSING_THROW, int $maxDepth = 8);
    public function render(string $template, array $vars = []): string;       // 渲染模板目录下的模板
    public function renderString(string $source, array $vars = []): string;   // 渲染一段模板源码
}

final class TplError extends \Exception
{
    public string $kind;   // syntax / missing / include / depth / cycle
    public int $line;      // 行号（1 起）；拿不到时为 0
}
```

## 怎么跑

```bash
php tests/run.php                  # 既有用例
php repro.php                      # 调用方的复现脚本
bash scripts/check.sh              # 全部验收场景，全过才退 0
bash scripts/check.sh -list        # 列出全部场景
bash scripts/check.sh --only path  # 只跑一组（subst / escape / path / include / robust）
```

`check/check.php` 是**固定验收入口，不要改它**；失败不早退，一次把问题全暴露出来。

## 版本前提

- PHP 8.0 及以上，只用标准库（`php-cli`），不需要 Composer、联网、数据库或中间件；
  不使用 mbstring 扩展。
- 判据完全确定：输入写死，不依赖时间、随机源或机器速度。
