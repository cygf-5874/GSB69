<?php

declare(strict_types=1);

namespace Phptpl;

/**
 * 把模板源码切成一串节点。
 *
 * 节点类型：
 *   - `text`    纯文本（原样输出）；
 *   - `var`     占位符 `{{ expr }}` / `{{{ expr }}}`（`raw` 标记是否不转义）；
 *   - `include` 片段引用 `{% include "片段名" %}`。
 *
 * 语法错误（未闭合的占位符 / 标签、空占位符、未知过滤器、未知标签）抛带行号的 TplError。
 */
final class Parser
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function parse(string $source): array
    {
        $nodes = [];
        $len = strlen($source);
        $pos = 0;
        $textStart = 0;

        while ($pos < $len) {
            $open = strpos($source, '{', $pos);
            if ($open === false) {
                break;
            }

            $two = substr($source, $open, 2);
            if ($two !== '{{' && $two !== '{%') {
                $pos = $open + 1;
                continue;
            }

            if ($open > $textStart) {
                $nodes[] = [
                    'type' => 'text',
                    'text' => substr($source, $textStart, $open - $textStart),
                ];
            }

            if ($two === '{%') {
                $close = strpos($source, '%}', $open + 2);
                if ($close === false) {
                    throw new TplError('未闭合的标签 {%', 'syntax', self::lineAt($source, $open));
                }
                $inner = trim(substr($source, $open + 2, $close - ($open + 2)));
                if (preg_match('/^include\s+(["\'])(.+?)\1$/s', $inner, $m) === 1) {
                    $nodes[] = [
                        'type' => 'include',
                        'name' => $m[2],
                        'line' => self::lineAt($source, $open),
                    ];
                } else {
                    throw new TplError(
                        '未知标签 {% ' . $inner . ' %}',
                        'syntax',
                        self::lineAt($source, $open)
                    );
                }
                $pos = $close + 2;
                $textStart = $pos;
                continue;
            }

            $raw = ($open + 2 < $len) && $source[$open + 2] === '{';
            $closeTag = $raw ? '}}}' : '}}';
            $close = strpos($source, $closeTag, $open + 2);
            if ($close === false) {
                throw new TplError(
                    $raw ? '未闭合的占位符 {{{' : '未闭合的占位符 {{',
                    'syntax',
                    self::lineAt($source, $open)
                );
            }

            $innerOffset = $open + 2 + ($raw ? 1 : 0);
            $inner = substr($source, $innerOffset, $close - $innerOffset);
            $end = $close + strlen($closeTag);

            $expr = trim($inner);
            if ($expr === '') {
                throw new TplError('占位符内容为空', 'syntax', self::lineAt($source, $open));
            }
            if (str_contains($expr, '|')) {
                throw new TplError('未知过滤器: ' . $expr, 'syntax', self::lineAt($source, $open));
            }

            $nodes[] = [
                'type' => 'var',
                'expr' => $expr,
                'raw' => $raw,
                'line' => self::lineAt($source, $open),
                'text' => substr($source, $open, $end - $open),
            ];

            $pos = $end;
            $textStart = $end;
        }

        if ($textStart < $len) {
            $nodes[] = [
                'type' => 'text',
                'text' => substr($source, $textStart),
            ];
        }

        return $nodes;
    }

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
