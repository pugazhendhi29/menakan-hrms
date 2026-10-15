<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator. Rules are pipe-separated:
 * required, nullable, email, date, time, integer, numeric, min:n / max:n (numbers),
 * minlen:n / maxlen:n (strings), in:a,b,c, after_or_equal:field, postcode, phone, regex:/x/
 */
final class Validator
{
    private array $errors = [];
    private array $validated = [];

    private function __construct(private array $data, private array $rules, private array $labels)
    {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                }
                $this->validated[$field] = null;
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $arg, $value, $field);
                if ($error !== null) {
                    $this->errors[$field] = $error;
                    break;
                }
            }
            $this->validated[$field] = $value;
        }
    }

    private function check(string $name, ?string $arg, mixed $value, string $field): ?string
    {
        $l = $this->label($field);
        $s = is_scalar($value) ? (string) $value : '';
        return match ($name) {
            'required', 'nullable' => null,
            'email'    => filter_var($s, FILTER_VALIDATE_EMAIL) ? null : "$l must be a valid email address.",
            'date'     => self::isDate($s) ? null : "$l must be a valid date.",
            'time'     => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $s) ? null : "$l must be a time in HH:MM format.",
            'integer'  => preg_match('/^-?\d+$/', $s) ? null : "$l must be a whole number.",
            'numeric'  => is_numeric($s) ? null : "$l must be a number.",
            'min'      => (is_numeric($s) && (float) $s >= (float) $arg) ? null : "$l must be at least $arg.",
            'max'      => (is_numeric($s) && (float) $s <= (float) $arg) ? null : "$l must be no more than $arg.",
            'minlen'   => mb_strlen($s) >= (int) $arg ? null : "$l must be at least $arg characters.",
            'maxlen'   => mb_strlen($s) <= (int) $arg ? null : "$l must be no more than $arg characters.",
            'in'       => in_array($s, explode(',', (string) $arg), true) ? null : "$l has an invalid value.",
            'after_or_equal' => (!self::isDate((string) ($this->data[$arg] ?? '')) || $s >= (string) $this->data[$arg])
                ? null : "$l must be on or after " . $this->label((string) $arg) . '.',
            'postcode' => preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/i', $s) ? null : "$l must be a valid UK postcode.",
            'phone'    => preg_match('/^[+\d][\d\s()-]{6,20}$/', $s) ? null : "$l must be a valid phone number.",
            'regex'    => preg_match((string) $arg, $s) ? null : "$l has an invalid format.",
            default    => throw new \InvalidArgumentException("Unknown rule $name"),
        };
    }

    public static function isDate(string $s): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        return $d !== false && $d->format('Y-m-d') === $s;
    }
}
