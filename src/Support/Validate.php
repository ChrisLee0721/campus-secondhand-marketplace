<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/**
 * 输入校验。收集所有字段的错误一起返回（422），
 * 而不是像上一版那样一遇到问题就 echo 然后继续往下跑。
 */
final class Validate
{
    /** @var array<string,string> 字段 => 错误说明 */
    private array $errors = [];

    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    private function value(string $field): mixed
    {
        $v = $this->data[$field] ?? null;
        return is_string($v) ? trim($v) : $v;
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $f) {
            $v = $this->value($f);
            if ($v === null || $v === '' || $v === []) {
                $this->errors[$f] = "{$f} 不能为空";
            }
        }
        return $this;
    }

    /** 字段存在时不允许为空（用于 PATCH 这类"只改提供的字段"的场景）。 */
    public function notEmpty(string ...$fields): self
    {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $this->data)) {
                continue;
            }
            $v = $this->value($f);
            if ($v === null || $v === '' || $v === []) {
                $this->errors[$f] = "{$f} 不能为空";
            }
        }
        return $this;
    }

    public function stringLength(string $field, int $min, int $max): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this; // 交给 required / notEmpty 报错，避免重复
        }
        if (!is_string($v)) {
            $this->errors[$field] = "{$field} 必须是字符串";
            return $this;
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$field] = "{$field} 至少 {$min} 个字符";
        } elseif ($len > $max) {
            $this->errors[$field] = "{$field} 不能超过 {$max} 个字符";
        }
        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_string($v)) {
            $this->errors[$field] = '电子邮箱格式不正确';
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = '电子邮箱格式不正确';
        } elseif (mb_strlen($v) > 100) {
            $this->errors[$field] = '电子邮箱过长';
        }
        return $this;
    }

    /**
     * 用户名只允许字母/数字/下划线/连字符/中日韩文字。
     * 上一版用 preg_match('/[^\p{L}\p{N}\s]/u') 检查却忘了 return，
     * 非法用户名照样入库。
     */
    public function username(string $field): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_string($v)) {
            $this->errors[$field] = '用户名必须是字符串';
            return $this;
        }
        if (!preg_match('/^[\p{Han}\p{Latin}\p{N}_-]+$/u', $v)) {
            $this->errors[$field] = '用户名只能包含文字、字母、数字、下划线和连字符';
        }
        return $this;
    }

    public function confirmed(string $field, string $confirmationField): self
    {
        $a = $this->value($field);
        $b = $this->value($confirmationField);
        if ($a === null || $b === null) {
            return $this;
        }
        if (!is_string($a) || !is_string($b)) {
            $this->errors[$confirmationField] = '两次输入的密码格式不正确';
            return $this;
        }
        if (!hash_equals($a, $b)) {
            $this->errors[$confirmationField] = '两次输入的密码不一致';
        }
        return $this;
    }

    public function in(string $field, array $allowed): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!in_array($v, $allowed, true)) {
            $this->errors[$field] = "{$field} 只能是: " . implode(', ', $allowed);
        }
        return $this;
    }

    public function numeric(string $field, float $min, float $max): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_numeric($v)) {
            $this->errors[$field] = "{$field} 必须是数字";
            return $this;
        }
        $n = (float) $v;
        if ($n < $min || $n > $max) {
            $this->errors[$field] = "{$field} 必须在 {$min} 到 {$max} 之间";
        }
        return $this;
    }

    /** 严格整数：拒绝 "1.9"、1.9 这类会被 (int) 悄悄截断的值。 */
    public function integer(string $field, int $min, int $max): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }

        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;
        } else {
            $this->errors[$field] = "{$field} 必须是整数";
            return $this;
        }

        if ($n < $min || $n > $max) {
            $this->errors[$field] = "{$field} 必须在 {$min} 到 {$max} 之间";
        }
        return $this;
    }

    /** 布尔值：接受 JSON true/false 以及表单的 "1"/"0"。 */
    public function boolean(string $field): self
    {
        if (!array_key_exists($field, $this->data)) {
            return $this;
        }

        $v = $this->value($field);
        if (is_bool($v) || $v === 0 || $v === 1 || $v === '0' || $v === '1') {
            return $this;
        }

        $this->errors[$field] = "{$field} 必须是布尔值";
        return $this;
    }

    /** 自定义规则：回调返回错误字符串则记录，返回 null 通过。 */
    public function rule(string $field, callable $fn): self
    {
        $msg = $fn($this->value($field), $this->data);
        if (is_string($msg) && $msg !== '') {
            $this->errors[$field] = $msg;
        }
        return $this;
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** 校验失败就抛 422，带上字段级 details。 */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw ApiException::validation($this->errors);
        }
        return $this->data;
    }
}
