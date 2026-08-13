<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Server-side validation.
 *
 * Client-side validation in L-SIAMS is a convenience only; this class is the
 * control. Every controller and API endpoint that accepts input runs through
 * it, and `validate()` throws rather than returning a boolean so a caller
 * cannot accidentally continue past a failure.
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,list<string>> */
    private array $errors = [];
    /** @var array<string,string> */
    private array $labels = [];
    /** @var array<string,mixed> */
    private array $validated = [];

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @param array<string,mixed> $data */
    public static function make(array $data): self
    {
        return new self($data);
    }

    /** @param array<string,string> $labels */
    public function labels(array $labels): self
    {
        $this->labels = $labels + $this->labels;

        return $this;
    }

    /**
     * @param array<string,string|list<string>> $rules  field => 'required|string|max:50'
     */
    public function rules(array $rules): self
    {
        foreach ($rules as $field => $ruleSet) {
            $ruleList = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $this->applyRules($field, $ruleList);
        }

        return $this;
    }

    /** @param list<string> $ruleList */
    private function applyRules(string $field, array $ruleList): void
    {
        $value    = $this->value($field);
        $isFilled = $this->isFilled($value);
        $nullable = in_array('nullable', $ruleList, true);

        // `required` first: everything downstream assumes presence.
        if (in_array('required', $ruleList, true) && !$isFilled) {
            $this->addError($field, sprintf('%s is required.', $this->label($field)));

            return;
        }

        if (!$isFilled) {
            if ($nullable || !in_array('required', $ruleList, true)) {
                if (array_key_exists($field, $this->data)) {
                    $this->validated[$field] = $nullable && $value === '' ? null : $value;
                }
            }

            return;
        }

        foreach ($ruleList as $rule) {
            if ($rule === 'required' || $rule === 'nullable' || $rule === '') {
                continue;
            }

            [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);

            if (!$this->checkRule($field, (string) $name, $parameter, $value)) {
                return; // stop at the first failing rule per field — cleaner UI
            }
        }

        $this->validated[$field] = $this->cast($field, $ruleList, $value);
    }

    private function checkRule(string $field, string $rule, ?string $parameter, mixed $value): bool
    {
        $label = $this->label($field);

        return match ($rule) {
            'string' => is_string($value) || is_numeric($value)
                ? true
                : $this->reject($field, sprintf('%s must be text.', $label)),

            'int', 'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false
                ? true
                : $this->reject($field, sprintf('%s must be a whole number.', $label)),

            'numeric' => is_numeric($value)
                ? true
                : $this->reject($field, sprintf('%s must be a number.', $label)),

            // is_bool first: an unchecked checkbox arrives as a real `false`,
            // and (string) false is the empty string, which is in none of the
            // accepted spellings. Casting before comparing therefore rejected
            // the one value the rule most obviously ought to accept.
            'bool', 'boolean' => is_bool($value)
                || in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)
                ? true
                : $this->reject($field, sprintf('%s must be true or false.', $label)),

            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false && strlen((string) $value) <= 190
                ? true
                : $this->reject($field, sprintf('%s must be a valid email address.', $label)),

            'min' => $this->checkMin($field, (string) $parameter, $value),
            'max' => $this->checkMax($field, (string) $parameter, $value),
            'between' => $this->checkBetween($field, (string) $parameter, $value),

            'in' => in_array((string) $value, explode(',', (string) $parameter), true)
                ? true
                : $this->reject($field, sprintf('%s must be one of: %s.', $label, str_replace(',', ', ', (string) $parameter))),

            'regex' => preg_match((string) $parameter, (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s has an invalid format.', $label)),

            // Conservative identifier charset — no spaces, no punctuation that
            // could be meaningful to a shell, a filesystem or a query builder.
            'slug' => preg_match('/^[A-Za-z0-9._-]+$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s may contain letters, numbers, dot, underscore and hyphen only.', $label)),

            'alpha_space' => preg_match("/^[\p{L}\p{M}' .-]+$/u", (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s may contain letters, spaces, apostrophes, periods and hyphens only.', $label)),

            // Case-insensitive on purpose. Every field that carries this rule is
            // passed through strtoupper() by its service before it is written,
            // so rejecting "math-101" refused a value the system was about to
            // normalise anyway. Worse, these inputs are styled
            // text-transform:uppercase, which only changes how the text is
            // drawn — the value stays as typed. Someone typing "math-101" saw
            // "MATH-101" on screen and was told it was not uppercase, with
            // nothing on the page to explain the contradiction.
            //
            // What the rule is actually for is keeping spaces, slashes and
            // punctuation out of an identifier that ends up in URLs, filenames
            // and report headings. It still does that.
            'code' => preg_match('/^[A-Za-z0-9-]+$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s may contain letters, numbers and hyphens only — no spaces.', $label)),

            'date' => $this->checkDate($field, (string) $value, 'Y-m-d'),
            'datetime' => $this->checkDate($field, (string) $value, 'Y-m-d H:i:s', ['Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', DATE_ATOM]),
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s must be a valid time (HH:MM).', $label)),

            'before_today' => $this->checkBeforeToday($field, (string) $value),

            'mac' => preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s must be a MAC address such as AA:BB:CC:DD:EE:FF.', $label)),

            'ip' => filter_var((string) $value, FILTER_VALIDATE_IP) !== false
                ? true
                : $this->reject($field, sprintf('%s must be a valid IP address.', $label)),

            'uid' => preg_match('/^[0-9A-Fa-f]{8,32}$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s must be a hexadecimal card UID (8–32 characters).', $label)),

            'uuid' => preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s must be a valid UUID.', $label)),

            'phone' => preg_match('/^[0-9+()\-\s]{7,20}$/', (string) $value) === 1
                ? true
                : $this->reject($field, sprintf('%s must be a valid contact number.', $label)),

            'array' => is_array($value)
                ? true
                : $this->reject($field, sprintf('%s must be a list.', $label)),

            'exists'  => $this->checkExists($field, (string) $parameter, $value),
            'unique'  => $this->checkUnique($field, (string) $parameter, $value),
            'confirmed' => (string) $value === (string) ($this->data[$field . '_confirmation'] ?? '')
                ? true
                : $this->reject($field, sprintf('%s confirmation does not match.', $label)),

            'json' => is_array(json_decode((string) $value, true))
                ? true
                : $this->reject($field, sprintf('%s must be valid JSON.', $label)),

            'no_html' => (string) $value === strip_tags((string) $value)
                ? true
                : $this->reject($field, sprintf('%s may not contain HTML.', $label)),

            default => true,
        };
    }

    private function checkMin(string $field, string $parameter, mixed $value): bool
    {
        $min = (float) $parameter;

        if (is_numeric($value) && !is_string($value)) {
            return (float) $value >= $min
                ? true
                : $this->reject($field, sprintf('%s must be at least %s.', $this->label($field), $parameter));
        }

        if (is_array($value)) {
            return count($value) >= $min
                ? true
                : $this->reject($field, sprintf('%s must contain at least %s item(s).', $this->label($field), $parameter));
        }

        return mb_strlen((string) $value) >= $min
            ? true
            : $this->reject($field, sprintf('%s must be at least %s characters.', $this->label($field), $parameter));
    }

    private function checkMax(string $field, string $parameter, mixed $value): bool
    {
        $max = (float) $parameter;

        if (is_numeric($value) && !is_string($value)) {
            return (float) $value <= $max
                ? true
                : $this->reject($field, sprintf('%s may not exceed %s.', $this->label($field), $parameter));
        }

        if (is_array($value)) {
            return count($value) <= $max
                ? true
                : $this->reject($field, sprintf('%s may contain at most %s item(s).', $this->label($field), $parameter));
        }

        return mb_strlen((string) $value) <= $max
            ? true
            : $this->reject($field, sprintf('%s may not exceed %s characters.', $this->label($field), $parameter));
    }

    private function checkBetween(string $field, string $parameter, mixed $value): bool
    {
        [$min, $max] = array_pad(explode(',', $parameter), 2, '0');

        return $this->checkMin($field, $min, $value) && $this->checkMax($field, $max, $value);
    }

    /** @param list<string> $alternates */
    private function checkDate(string $field, string $value, string $format, array $alternates = []): bool
    {
        foreach (array_merge([$format], $alternates) as $candidate) {
            $parsed = DateTimeImmutable::createFromFormat($candidate, $value);
            if ($parsed !== false) {
                return true;
            }
        }

        return $this->reject($field, sprintf('%s must be a valid date.', $this->label($field)));
    }

    private function checkBeforeToday(string $field, string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false) {
            return $this->reject($field, sprintf('%s must be a valid date.', $this->label($field)));
        }

        return $parsed < new DateTimeImmutable('today')
            ? true
            : $this->reject($field, sprintf('%s must be in the past.', $this->label($field)));
    }

    /** `exists:table,column` — verifies a foreign reference before the FK does, for a friendly message. */
    private function checkExists(string $field, string $parameter, mixed $value): bool
    {
        [$table, $column, $extra] = array_pad(explode(',', $parameter), 3, null);
        $table  = self::safeIdentifier((string) $table);
        $column = self::safeIdentifier((string) ($column ?? 'id'));

        $sql      = sprintf('SELECT 1 FROM `%s` WHERE `%s` = :value', $table, $column);
        $bindings = ['value' => $value];

        if ($extra !== null && str_contains($extra, '=')) {
            [$extraColumn, $extraValue] = explode('=', $extra, 2);
            $sql .= sprintf(' AND `%s` = :extra', self::safeIdentifier($extraColumn));
            $bindings['extra'] = $extraValue;
        }

        $sql .= ' LIMIT 1';

        return Database::instance()->scalar($sql, $bindings) !== null
            ? true
            : $this->reject($field, sprintf('The selected %s does not exist.', strtolower($this->label($field))));
    }

    /** `unique:table,column,ignoreValue,ignoreColumn` */
    private function checkUnique(string $field, string $parameter, mixed $value): bool
    {
        [$table, $column, $ignoreValue, $ignoreColumn] = array_pad(explode(',', $parameter), 4, null);
        $table  = self::safeIdentifier((string) $table);
        $column = self::safeIdentifier((string) ($column ?? $field));

        $sql      = sprintf('SELECT 1 FROM `%s` WHERE `%s` = :value', $table, $column);
        $bindings = ['value' => $value];

        if ($ignoreValue !== null && $ignoreValue !== '' && $ignoreValue !== '0') {
            $sql .= sprintf(' AND `%s` <> :ignore', self::safeIdentifier((string) ($ignoreColumn ?? 'id')));
            $bindings['ignore'] = $ignoreValue;
        }

        $sql .= ' LIMIT 1';

        return Database::instance()->scalar($sql, $bindings) === null
            ? true
            : $this->reject($field, sprintf('%s is already in use.', $this->label($field)));
    }

    /** Identifiers never come from user input, but this guarantees it structurally. */
    private static function safeIdentifier(string $identifier): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_]/', '', $identifier);
    }

    /** @param list<string> $ruleList */
    private function cast(string $field, array $ruleList, mixed $value): mixed
    {
        foreach ($ruleList as $rule) {
            $name = explode(':', $rule, 2)[0];

            if ($name === 'int' || $name === 'integer') {
                return (int) $value;
            }
            if ($name === 'numeric') {
                return (float) $value;
            }
            if ($name === 'bool' || $name === 'boolean') {
                return is_bool($value)
                    ? $value
                    : in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
            }
            if ($name === 'array') {
                return is_array($value) ? $value : [];
            }
        }

        return is_string($value) ? trim($value) : $value;
    }

    private function value(string $field): mixed
    {
        if (str_contains($field, '.')) {
            $cursor = $this->data;
            foreach (explode('.', $field) as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$segment];
            }

            return $cursor;
        }

        return $this->data[$field] ?? null;
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace(['_id', '_'], ['', ' '], $field));
    }

    private function reject(string $field, string $message): bool
    {
        $this->addError($field, $message);

        return false;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field][] = $message;

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,list<string>> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    /** @return array<string,mixed> */
    public function validated(): array
    {
        return $this->validated;
    }
}
