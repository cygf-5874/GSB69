<?php

declare(strict_types=1);

/**
 * 调用方给的复现脚本。
 *
 * 用法：php repro.php
 *
 * 场景：模板里引用了 `user.email`，而传进来的 user 只有 name —— 按 README「对外保证」
 * 第 3 条，默认策略下这属于缺失变量。这里只跑一段最简单的输入，用来核对当前实现的输出。
 */

require __DIR__ . '/src/TplError.php';
require __DIR__ . '/src/Escaper.php';
require __DIR__ . '/src/Parser.php';
require __DIR__ . '/src/Engine.php';

use Phptpl\Engine;
use Phptpl\TplError;

$engine = new Engine(__DIR__ . '/templates');

try {
    $html = $engine->renderString("Hello, {{ user.email }}!\n", ['user' => ['name' => 'Ada']]);
    echo "渲染结果:\n";
    echo $html;
} catch (TplError $exc) {
    echo "TplError[{$exc->kind}] line {$exc->line}: {$exc->getMessage()}\n";
}
