<?php
declare(strict_types=1);

namespace App\Support;

use App\Http\ApiException;

/**
 * Input validation. Every field error is collected and returned together as 422,
 * instead of echoing on the first problem and carrying on like the previous version.
 */
final class Validate
{
    /** @var array<string,string> field => error message */
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
                $this->errors[$f] = "{$f} is required";
            }
        }
        return $this;
    }

    /** When the field is present it must not be empty (for PATCH style partial updates). */
    public function notEmpty(string ...$fields): self
    {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $this->data)) {
                continue;
            }
            $v = $this->value($f);
            if ($v === null || $v === '' || $v === []) {
                $this->errors[$f] = "{$f} is required";
            }
        }
        return $this;
    }

    public function stringLength(string $field, int $min, int $max): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this; // let required / notEmpty report it, avoid duplicates
        }
        if (!is_string($v)) {
            $this->errors[$field] = "{$field} must be a string";
            return $this;
        }
        $len = mb_strlen($v);
        if ($len < $min) {
            $this->errors[$field] = "{$field} must be at least {$min} characters";
        } elseif ($len > $max) {
            $this->errors[$field] = "{$field} must not exceed {$max} characters";
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
            $this->errors[$field] = 'Invalid email address';
            return $this;
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Invalid email address';
        } elseif (mb_strlen($v) > 100) {
            $this->errors[$field] = 'Email is too long';
        }
        return $this;
    }

    /**
     * Usernames allow letters, digits, underscore, hyphen and CJK characters.
     * The previous version ran preg_match('/[^\p{L}\p{N}\s]/u') but forgot to return,
     * so invalid usernames were stored anyway.
     */
    public function username(string $field): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            return $this;
        }
        if (!is_string($v)) {
            $this->errors[$field] = 'Username must be a string';
            return $this;
        }
        if (!preg_match('/^[\p{Han}\p{Latin}\p{N}_-]+$/u', $v)) {
            $this->errors[$field] = 'Username may contain only letters, numbers, underscores, and hyphens';
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
            $this->errors[$confirmationField] = 'The password confirmation is invalid';
            return $this;
        }
        if (!hash_equals($a, $b)) {
            $this->errors[$confirmationField] = 'The passwords do not match';
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
            $this->errors[$field] = "{$field} must be one of: " . implode(', ', $allowed);
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
            $this->errors[$field] = "{$field} must be a number";
            return $this;
        }
        $n = (float) $v;
        if ($n < $min || $n > $max) {
            $this->errors[$field] = "{$field} must be between {$min} and {$max}";
        }
        return $this;
    }

    /** Strict integer: rejects "1.9" and 1.9, which (int) would silently truncate. */
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
            $this->errors[$field] = "{$field} must be an integer";
            return $this;
        }

        if ($n < $min || $n > $max) {
            $this->errors[$field] = "{$field} must be between {$min} and {$max}";
        }
        return $this;
    }

    /** Boolean: accepts JSON true/false and the form values "1"/"0". */
    public function boolean(string $field): self
    {
        if (!array_key_exists($field, $this->data)) {
            return $this;
        }

        $v = $this->value($field);
        if (is_bool($v) || $v === 0 || $v === 1 || $v === '0' || $v === '1') {
            return $this;
        }

        $this->errors[$field] = "{$field} must be a boolean";
        return $this;
    }

    /** Custom rule: a returned error string fails the field, null passes. */
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

    /** Throw 422 with field level details when validation fails. */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw ApiException::validation($this->errors);
        }
        return $this->data;
    }
}
