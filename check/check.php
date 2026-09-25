<?php

declare(strict_types=1);

/**
 * 固定验收入口：phptpl 的「对外保证」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                    跑全部场景，全过才退 0
 *   php check/check.php -list              列出全部场景
 *   php check/check.php --only subst       只跑一组
 *   php check/check.php --only path,escape 只跑指定的几组
 *
 * 五组共 10 个场景：
 *   subst   3 —— 简单替换 + 逐字节确定、嵌套路径与内置 length、缺失变量策略三选一；
 *   escape  2 —— 默认转义 & < > " '（含非法 UTF-8 替换）、三花括号 raw 与语法错误行号；
 *   path    2 —— 点分路径与扁平键不串味、数字下标；
 *   include 2 —— 按模板目录相对解析片段、递归深度上限 8；
 *   robust  1 —— 循环 include 被检测（不耗尽内存）+ 模板里不执行任意 PHP。
 *
 * 只依赖 PHP 8 标准库；判据完全确定，不读时间、不用随机源。失败不早退。
 */

require __DIR__ . '/../src/TplError.php';
require __DIR__ . '/../src/Escaper.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Engine.php';

use Phptpl\Engine;
use Phptpl\TplError;

// ---------------------------------------------------------------------------
// 子进程模式：循环 include 的看门狗（受内存上限与超时约束）
// ---------------------------------------------------------------------------

if (($argv[1] ?? '') === '__cycle-child') {
    $dir = $argv[2] ?? '';
    try {
        (new Engine($dir))->render('a.tpl', []);
        echo 'NO-THROW';
    } catch (TplError $exc) {
        echo 'OK:' . $exc->kind;
    } catch (\Throwable $exc) {
        echo 'ERR:' . get_class($exc);
    }
    exit(0);
}

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

// ---------------------------------------------------------------------------
// 工具
// ---------------------------------------------------------------------------

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function tpl_root(): string
{
    return dirname(__DIR__);
}

function engine(string $missingPolicy = Engine::MISSING_THROW): Engine
{
    return new Engine(tpl_root() . '/templates', $missingPolicy);
}

function expect_same($want, $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure(
            $what,
            var_export($want, true),
            var_export($got, true)
        );
    }
}

function expect_true(bool $cond, string $what, string $expected = 'true'): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, 'false');
    }
}

/** 断言 $fn() 抛出 TplError，且 kind（与可选的行号）匹配。 */
function expect_error(callable $fn, string $kind, string $what, ?int $line = null): void
{
    try {
        $fn();
    } catch (TplError $exc) {
        if ($exc->kind !== $kind) {
            throw new CheckFailure(
                $what . '（kind 不符）',
                'TplError(kind=' . $kind . ')',
                'TplError(kind=' . $exc->kind . ')'
            );
        }
        if ($line !== null && $exc->line !== $line) {
            throw new CheckFailure(
                $what . '（行号不符）',
                'line=' . $line,
                'line=' . $exc->line
            );
        }

        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure($what, 'TplError(kind=' . $kind . ')', get_class($exc) . ': ' . $exc->getMessage());
    }

    throw new CheckFailure($what, 'TplError(kind=' . $kind . ')', '未抛错（正常返回）');
}

/** 造一个空的临时模板目录（不含中文路径干扰）。 */
function fresh_dir(string $tag): string
{
    $base = rtrim(sys_get_temp_dir(), "/\\") . '/phptpl-check-' . $tag . '-' . getmypid();
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true) && !is_dir($base)) {
            throw new CheckFailure('无法创建临时模板目录', $base, 'mkdir 失败');
        }
    }
    foreach (glob($base . '/*') ?: [] as $old) {
        @unlink($old);
    }

    return $base;
}

function write_tpl(string $dir, string $name, string $content): void
{
    if (file_put_contents($dir . '/' . $name, $content) === false) {
        throw new CheckFailure('无法写入临时模板', $name, 'file_put_contents 失败');
    }
}

/**
 * 在受控内存/超时下跑一个子进程，返回 [退出码, 输出]。
 *
 * @param string[] $args
 * @return array{0: int|null, 1: string}
 */
function run_child(array $args, float $timeout): array
{
    $cmd = array_merge([
        PHP_BINARY,
        '-d', 'memory_limit=64M',
        '-d', 'max_execution_time=20',
        '-d', 'display_errors=1',
        __FILE__,
    ], $args);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [null, 'proc_open 失败'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $exit = null;
    $deadline = microtime(true) + $timeout;

    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);

        $status = proc_get_status($proc);
        if (!$status['running']) {
            $exit = $status['exitcode'];
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);
            break;
        }

        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $out .= "\n[子进程超时]";
            $exit = -1;
            break;
        }

        usleep(10000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($proc);
    if ($exit === null) {
        $exit = $closeCode;
    }

    return [$exit, $out];
}

// ---------------------------------------------------------------------------
// [subst] 3
// ---------------------------------------------------------------------------

$subst = [
    tk('basic', '简单替换 + 逐字节确定（空行保留、不 trim、两次一致）', function (): void {
        expect_same("Hello, World!\n", engine()->renderString("Hello, {{ name }}!\n", ['name' => 'World']), '简单替换');

        $plain = "line1\n\n  line3  \n\ttail\n";
        expect_same($plain, engine()->renderString($plain), '无占位符时逐字节原样输出');

        expect_same('  X  ', engine()->renderString('  {{ a }}  ', ['a' => 'X']), '不做 trim');

        $src = "{{ a }}\n\n{{ b }}\n";
        $first = engine()->renderString($src, ['a' => 'A', 'b' => 'B']);
        $second = engine()->renderString($src, ['a' => 'A', 'b' => 'B']);
        expect_same($first, $second, '两次渲染结果');
        expect_same("A\n\nB\n", $first, '空行保留');
    }),

    tk('length', '嵌套数组路径与内置 length', function (): void {
        expect_same(
            '3',
            engine()->renderString('{{ items.length }}', ['items' => ['a', 'b', 'c']]),
            '数组 length'
        );
        expect_same(
            '5',
            engine()->renderString('{{ word.length }}', ['word' => 'hello']),
            '字符串 length（字节）'
        );
        expect_same(
            'Ada',
            engine()->renderString('{{ user.name.first }}', ['user' => ['name' => ['first' => 'Ada']]]),
            '三层嵌套路径'
        );
        expect_same(
            "Ada has 3 items.\n",
            engine()->render('card.tpl', ['user' => ['name' => 'Ada'], 'items' => [1, 2, 3]]),
            'card.tpl 渲染'
        );
    }),

    tk('missing', '缺失变量策略：throw（默认）/ empty / keep', function (): void {
        $src = "line1\n{{ user.email }}\n";

        expect_error(
            function () use ($src): void {
                engine()->renderString($src, ['user' => ['id' => 1]]);
            },
            'missing',
            '默认策略下缺失变量应抛 TplError',
            2
        );

        expect_same(
            "line1\n\n",
            engine(Engine::MISSING_EMPTY)->renderString($src, ['user' => ['id' => 1]]),
            'empty 策略：缺失变量渲染成空串'
        );

        expect_same(
            "line1\n{{ user.email }}\n",
            engine(Engine::MISSING_KEEP)->renderString($src, ['user' => ['id' => 1]]),
            'keep 策略：缺失变量保留原样'
        );
    }),
];

// ---------------------------------------------------------------------------
// [escape] 2
// ---------------------------------------------------------------------------

$escape = [
    tk('default', '默认转义 & < > " \' 与非法 UTF-8 替换', function (): void {
        $value = "O'Brien <b>\"q\"</b> & more";
        expect_same(
            'O&#039;Brien &lt;b&gt;&quot;q&quot;&lt;/b&gt; &amp; more',
            engine()->renderString('{{ v }}', ['v' => $value]),
            '五个字符一律转义'
        );

        $bad = "a\xC3\x28b";
        expect_same(
            "a\xEF\xBF\xBD(b",
            engine()->renderString('{{ v }}', ['v' => $bad]),
            '非法 UTF-8 用 U+FFFD 替换而不是丢字符'
        );
    }),

    tk('raw', '三花括号 raw 与语法错误行号', function (): void {
        expect_same(
            '<b>&"</b>',
            engine()->renderString('{{{ v }}}', ['v' => '<b>&"</b>']),
            'raw 不转义'
        );
        expect_same(
            '<b>|&lt;b&gt;',
            engine()->renderString('{{{ a }}}|{{ a }}', ['a' => '<b>']),
            'raw 与转义并存'
        );

        expect_error(
            function (): void {
                engine()->renderString("ok\n{{{ a }}\n");
            },
            'syntax',
            '未闭合的 raw 应抛语法错',
            2
        );
        expect_error(
            function (): void {
                engine()->renderString("x\n{{ a | upper }}\n");
            },
            'syntax',
            '未知过滤器应抛语法错',
            2
        );
        expect_error(
            function (): void {
                engine()->renderString("{{ a\n");
            },
            'syntax',
            '未闭合的占位符应抛语法错',
            1
        );
    }),
];

// ---------------------------------------------------------------------------
// [path] 2
// ---------------------------------------------------------------------------

$path = [
    tk('bleed', '点分路径与扁平键互不串味', function (): void {
        expect_same(
            'AB|B',
            engine()->renderString('{{ ab }}|{{ a.b }}', ['a' => ['b' => 'B'], 'ab' => 'AB']),
            'ab 与 a.b 各自取值'
        );
        expect_same(
            'Z',
            engine()->renderString(
                '{{ x.y.z }}',
                ['x' => ['y' => ['z' => 'Z']], 'xyz' => 'XYZ', 'xy' => ['z' => 'Q']]
            ),
            '深路径不受扁平键 xyz / 前缀键 xy 干扰'
        );
    }),

    tk('numeric', '数字下标取数组元素', function (): void {
        expect_same(
            'x|z',
            engine()->renderString('{{ list.0 }}|{{ list.2 }}', ['list' => ['x', 'y', 'z']]),
            '数字下标'
        );
        expect_same(
            'Ada',
            engine()->renderString('{{ rows.1.name }}', ['rows' => [['name' => 'Bob'], ['name' => 'Ada']]]),
            '数字下标后接字段'
        );
    }),
];

// ---------------------------------------------------------------------------
// [include] 2
// ---------------------------------------------------------------------------

$include = [
    tk('resolve', '片段按模板目录相对路径解析', function (): void {
        expect_same(
            "Home\n-- phptpl --\n",
            engine()->render('page.tpl', ['title' => 'Home', 'site' => 'phptpl']),
            'page.tpl 引用 snippets/footer.tpl'
        );
        expect_error(
            function (): void {
                engine()->renderString('{% include "nope/missing.tpl" %}');
            },
            'include',
            '片段不存在应抛 TplError'
        );
    }),

    tk('depth', '递归深度上限 8，超过抛 TplError', function (): void {
        $ok = fresh_dir('depth-ok');
        for ($i = 1; $i <= 9; $i++) {
            $next = $i < 9 ? '{% include "d' . ($i + 1) . '.tpl" %}' : '';
            write_tpl($ok, 'd' . $i . '.tpl', (string) $i . $next);
        }
        expect_same(
            '123456789',
            (new Engine($ok))->render('d1.tpl', []),
            '9 层链（最深深度 8）应当渲染成功'
        );

        $deep = fresh_dir('depth-deep');
        for ($i = 1; $i <= 10; $i++) {
            $next = $i < 10 ? '{% include "e' . ($i + 1) . '.tpl" %}' : '';
            write_tpl($deep, 'e' . $i . '.tpl', (string) $i . $next);
        }
        expect_error(
            function () use ($deep): void {
                (new Engine($deep))->render('e1.tpl', []);
            },
            'depth',
            '10 层链（最深深度 9）应抛 TplError(kind=depth)'
        );
    }),
];

// ---------------------------------------------------------------------------
// [robust] 1
// ---------------------------------------------------------------------------

$robust = [
    tk('cycle', '循环 include 被检测 + 不执行任意 PHP', function (): void {
        foreach (glob(tpl_root() . '/src/*.php') ?: [] as $file) {
            $code = (string) file_get_contents($file);
            expect_true(
                preg_match('/\beval\s*\(/', $code) !== 1,
                basename($file) . ' 实现里不得出现 eval'
            );
        }

        expect_same(
            "<?= 6*7 ?>\n",
            engine()->renderString("<?= 6*7 ?>\n", []),
            '模板里的字面 PHP 不被执行，原样输出'
        );
        expect_same(
            "&lt;?= 6*7 ?&gt;\n",
            engine()->renderString("{{ v }}\n", ['v' => '<?= 6*7 ?>']),
            '变量里的 PHP 只当普通文本转义'
        );

        $dir = fresh_dir('cycle');
        write_tpl($dir, 'a.tpl', 'A{% include "b.tpl" %}');
        write_tpl($dir, 'b.tpl', 'B{% include "a.tpl" %}');

        [$code, $out] = run_child(['__cycle-child', $dir], 15.0);
        expect_same(0, $code, '循环 include 的子进程退出码（应为 0：抛 TplError 而非内存耗尽）');
        expect_true(
            strpos($out, 'OK:cycle') !== false,
            '循环 include 应抛 TplError(kind=cycle)',
            '输出包含 OK:cycle'
        );
    }),
];

$GROUPS = [
    'subst' => $subst,
    'escape' => $escape,
    'path' => $path,
    'include' => $include,
    'robust' => $robust,
];

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$flat = [];
foreach ($GROUPS as $group => $scenarios) {
    foreach ($scenarios as $scenario) {
        $flat[] = [$group, $scenario['name'], $scenario['why'], $scenario['fn']];
    }
}

$argv = $argv ?? [];
$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（subst/escape/path/include/robust）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-7s] %-10s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=正常返回 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

$total = count($selected);
printf("结果：通过 %d/%d\n", $passed, $total);
exit($failed === 0 ? 0 : 1);
