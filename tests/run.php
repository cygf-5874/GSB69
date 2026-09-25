<?php

declare(strict_types=1);

/**
 * phptpl 的既有用例（自建断言 runner）。
 *
 * 用法：php tests/run.php
 *
 * 这一组用例只覆盖最简单的 `{{ name }}` 形式：单个 / 多个 / 相邻占位符、
 * 纯文本与空行原样输出、模板文件渲染、两次渲染一致。未覆盖嵌套路径、转义细节、
 * 缺失变量策略、片段引用与三花括号 —— 那些以 README「对外保证」为准。
 */

require __DIR__ . '/../src/TplError.php';
require __DIR__ . '/../src/Escaper.php';
require __DIR__ . '/../src/Parser.php';
require __DIR__ . '/../src/Engine.php';

use Phptpl\Engine;

final class TestFailure extends \Exception
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

function assert_same($expected, $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            $what,
            var_export($expected, true),
            var_export($actual, true)
        );
    }
}

function assert_true(bool $cond, string $what): void
{
    if (!$cond) {
        throw new TestFailure($what, 'true', 'false');
    }
}

function engine(): Engine
{
    return new Engine(__DIR__ . '/../templates');
}

function render(string $source, array $vars = []): string
{
    return engine()->renderString($source, $vars);
}

$TESTS = [];

function t(string $name, callable $fn): void
{
    global $TESTS;
    $TESTS[] = [$name, $fn];
}

// ---------------------------------------------------------------------------
// 用例
// ---------------------------------------------------------------------------

t('单个占位符', function (): void {
    assert_same('Hello, World!', render('Hello, {{ name }}!', ['name' => 'World']), '单个占位符替换');
});

t('多个占位符', function (): void {
    assert_same('1-2-3', render('{{ a }}-{{ b }}-{{ c }}', ['a' => 1, 'b' => 2, 'c' => 3]), '多个占位符');
});

t('占位符内外的空白不影响取值', function (): void {
    assert_same('World', render('{{name}}', ['name' => 'World']), '紧贴花括号');
    assert_same('World', render('{{    name    }}', ['name' => 'World']), '花括号内留白');
});

t('相邻占位符之间不插入分隔', function (): void {
    assert_same('xy', render('{{ a }}{{ b }}', ['a' => 'x', 'b' => 'y']), '相邻占位符');
});

t('纯文本模板原样输出', function (): void {
    $src = 'no placeholders here';
    assert_same($src, render($src), '无占位符');
});

t('空模板输出空串', function (): void {
    assert_same('', render(''), '空模板');
});

t('空行与行尾空白原样保留', function (): void {
    $src = "a\n\n \nb\n";
    assert_same($src, render($src), '空行与行尾空白');
});

t('不做 trim', function (): void {
    assert_same('  X  ', render('  {{ a }}  ', ['a' => 'X']), '前后空白保留');
});

t('两次渲染逐字节一致', function (): void {
    $src = "{{ a }}\n\n{{ b }}\n";
    $first = render($src, ['a' => 'A', 'b' => 'B']);
    $second = render($src, ['a' => 'A', 'b' => 'B']);
    assert_same($first, $second, '两次渲染结果');
    assert_same("A\n\nB\n", $first, '渲染内容');
});

t('数字与布尔值转成字符串', function (): void {
    assert_same('42', render('{{ n }}', ['n' => 42]), '整数');
    assert_same('false', render('{{ b }}', ['b' => false]), '布尔 false');
});

t('从模板文件渲染', function (): void {
    assert_same("Hello, Ada!\n", engine()->render('hello.tpl', ['name' => 'Ada']), 'hello.tpl');
});

t('占位符在行首与行尾', function (): void {
    assert_same(
        "A mid B\n",
        render("{{ a }} mid {{ b }}\n", ['a' => 'A', 'b' => 'B']),
        '行首行尾占位符'
    );
});

t('变量值为空串时输出空', function (): void {
    assert_same('', render('{{ a }}', ['a' => '']), '空串变量');
});

// ---------------------------------------------------------------------------
// runner
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
foreach ($TESTS as [$name, $fn]) {
    try {
        $fn();
    } catch (TestFailure $exc) {
        $failed++;
        echo "FAIL {$name}  期望={$exc->expected} 实际={$exc->actual}（{$exc->getMessage()}）\n";
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        echo 'FAIL ' . $name . '  期望=正常返回 实际=' . get_class($exc) . ': ' . $exc->getMessage() . "\n";
        continue;
    }

    $passed++;
    echo "PASS {$name}\n";
}

$total = count($TESTS);
echo "结果：通过 {$passed}/{$total}\n";
exit($failed === 0 ? 0 : 1);
