phptpl 现在是「能跑但不对」的状态：PHP 8 的模板占位符渲染库，php-cli，
无第三方依赖，只用标准库，不许用 composer。
README「对外保证」一节列了 8 条；src/ 下是完整实现；tests/run.php 里 13 个用例当前全绿
—— 但它们只覆盖最简单的 {{name}} 形式。
repro.php 是调用方给的复现脚本，跑一下能看到一处与「对外保证」矛盾的结果。

任务：修掉实现里违反那 8 条保证的地方。

验收（check/check.php 是固定验收程序，别改）：
- bash scripts/check.sh 退出码 0，10 个场景全过（subst 3 + escape 2 + path 2 + include 2 + robust 1）；
- php tests/run.php 全绿。

约束：
1. 不改 check/、不改 repro.php，不改 tests/ 里既有用例的断言；
   公开方法签名与 TplError 的字段不变。
2. 只用标准库；不许用 mbstring（本机 php-cli 未启用）；实现里不许出现 eval。
3. 渲染结果按字节比对，不许 trim、不许重排空行。
4. 判据不含时间、随机数与网络。
