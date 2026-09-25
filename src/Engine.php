<?php

declare(strict_types=1);

namespace Phptpl;

/**
 * 模板渲染引擎。
 *
 * 用法：
 *   $engine = new Engine(__DIR__ . '/templates');
 *   echo $engine->render('page.tpl', ['title' => 'Home']);
 *   echo $engine->renderString('Hello, {{ name }}!', ['name' => 'World']);
 *
 * 语义见 README「对外保证」一节：路径解析、默认转义、缺失变量策略、片段引用、
 * 逐字节确定的输出。
 */
final class Engine
{
    public const MISSING_THROW = 'throw';
    public const MISSING_EMPTY = 'empty';
    public const MISSING_KEEP = 'keep';

    private string $dir;

    private string $missing;

    private int $maxDepth;

    public function __construct(
        string $templateDir,
        string $missingPolicy = self::MISSING_THROW,
        int $maxDepth = 8
    ) {
        if (!in_array($missingPolicy, [self::MISSING_THROW, self::MISSING_EMPTY, self::MISSING_KEEP], true)) {
            throw new \InvalidArgumentException('未知的缺失变量策略: ' . $missingPolicy);
        }
        if ($maxDepth < 1) {
            throw new \InvalidArgumentException('maxDepth 必须 >= 1');
        }

        $this->dir = rtrim($templateDir, "/\\");
        $this->missing = $missingPolicy;
        $this->maxDepth = $maxDepth;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function missingPolicy(): string
    {
        return $this->missing;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    /** 渲染模板目录下的一个模板。 */
    public function render(string $template, array $vars = []): string
    {
        return $this->renderSource($this->load($template), $vars, 0);
    }

    /** 渲染一段模板源码。 */
    public function renderString(string $source, array $vars = []): string
    {
        return $this->renderSource($source, $vars, 0);
    }

    private function renderSource(string $source, array $vars, int $depth): string
    {
        $nodes = Parser::parse($source);
        $out = '';

        foreach ($nodes as $node) {
            if ($node['type'] === 'text') {
                $out .= $node['text'];
                continue;
            }

            if ($node['type'] === 'include') {
                $src = $this->load($node['name']);
                $out .= $this->renderSource($src, $vars, $depth + 1);
                continue;
            }

            [$found, $value] = $this->lookup($vars, $node['expr']);

            if (!$found) {
                if ($this->missing === self::MISSING_EMPTY) {
                    continue;
                }
                if ($this->missing === self::MISSING_KEEP) {
                    $out .= $node['text'];
                    continue;
                }
            }

            $text = $this->stringify($value);
            $out .= $node['raw'] ? $text : Escaper::html($text);
        }

        return $out;
    }

    private function load(string $name): string
    {
        $path = $this->dir . '/' . $name;
        if (!is_file($path)) {
            throw new TplError('找不到模板片段: ' . $name, 'include', 0);
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw new TplError('无法读取模板片段: ' . $name, 'include', 0);
        }

        return $source;
    }

    /**
     * 解析占位符表达式。
     *
     * @return array{0: bool, 1: mixed} [是否找到, 取到的值]
     */
    private function lookup(array $vars, string $expr): array
    {
        if (array_key_exists($expr, $vars)) {
            return [true, $vars[$expr]];
        }

        $flat = str_replace('.', '', $expr);
        if ($flat !== $expr && array_key_exists($flat, $vars)) {
            return [true, $vars[$flat]];
        }

        $cur = $vars;
        foreach (explode('.', $expr) as $seg) {
            if ($seg === 'length') {
                if (is_array($cur)) {
                    $cur = count($cur);
                    continue;
                }
                if (is_string($cur)) {
                    $cur = strlen($cur);
                    continue;
                }
            }

            if (ctype_digit($seg)) {
                if (is_object($cur) && is_callable([$cur, $seg])) {
                    $cur = $cur->{$seg}();
                    continue;
                }

                return [false, null];
            }

            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
                continue;
            }

            if (is_object($cur) && method_exists($cur, $seg)) {
                $cur = $cur->{$seg}();
                continue;
            }

            return [false, null];
        }

        return [true, $cur];
    }

    private function stringify($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $json === false ? '' : $json;
        }

        return (string) $value;
    }
}
