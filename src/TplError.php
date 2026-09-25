<?php

declare(strict_types=1);

namespace Phptpl;

/**
 * 模板层的统一异常。
 *
 * 字段是对外契约的一部分：
 *   - `kind` 取值 `syntax` / `missing` / `include` / `depth` / `cycle`；
 *   - `line` 是出错位置的行号（从 1 开始），拿不到行号时为 0。
 */
final class TplError extends \Exception
{
    public string $kind;

    public int $line;

    public function __construct(string $message, string $kind = 'syntax', int $line = 0)
    {
        parent::__construct($message);
        $this->kind = $kind;
        $this->line = $line;
    }
}
