<?php

declare(strict_types=1);

namespace Phptpl;

/**
 * 默认输出转义。
 *
 * 语义见 README「对外保证」第 2 条：`& < > " '` 一律转义，非法 UTF-8 序列用替换字符
 * 顶掉而不是丢字符。编码固定 UTF-8，不使用 mbstring。
 */
final class Escaper
{
    public static function html(string $value): string
    {
        return htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
    }
}
