<?php

declare(strict_types=1);

namespace TurathManager\Core;

/**
 * التحقق من المدخلات وتنظيفها. يرجع البيانات النظيفة أو يرمي خطأ 422.
 *
 * القواعد المدعومة: required, nullable, string, max:N, min:N, int, decimal, bool,
 * email, phone, uuid, datetime, date, in:a|b, array, url
 */
final class Validator
{
    public function __construct(private readonly Clock $clock, private readonly string $countryCode = '967')
    {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels أسماء عربية للحقول
     * @return array<string,mixed>
     */
    public function validate(array $data, array $rules, array $labels = [], bool $partial = false): array
    {
        $clean = [];
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $label = $labels[$field] ?? $field;
            $ruleList = explode('|', $ruleString);
            $present = array_key_exists($field, $data);
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $empty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($partial && !$present) {
                continue;
            }
            if ($empty) {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = "الحقل {$label} مطلوب";
                } elseif ($present || in_array('nullable', $ruleList, true)) {
                    $clean[$field] = null;
                }
                continue;
            }

            try {
                $clean[$field] = $this->apply($value, $ruleList, $label);
            } catch (\InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }
        return $clean;
    }

    /** @param list<string> $rules */
    private function apply(mixed $value, array $rules, string $label): mixed
    {
        $numeric = false;
        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            switch ($name) {
                case 'required':
                case 'nullable':
                    break;
                case 'string':
                    if (!is_scalar($value)) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون نصاً");
                    }
                    $value = (string) $value;
                    break;
                case 'max':
                    if ($numeric ? (float) $value > (float) $arg : mb_strlen((string) $value) > (int) $arg) {
                        throw new \InvalidArgumentException($numeric ? "الحقل {$label} يجب ألا يتجاوز {$arg}" : "الحقل {$label} يجب ألا يتجاوز {$arg} حرفاً");
                    }
                    break;
                case 'min':
                    if ($numeric ? (float) $value < (float) $arg : mb_strlen((string) $value) < (int) $arg) {
                        throw new \InvalidArgumentException($numeric ? "الحقل {$label} يجب ألا يقل عن {$arg}" : "الحقل {$label} يجب ألا يقل عن {$arg} أحرف");
                    }
                    break;
                case 'int':
                    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون عدداً صحيحاً");
                    }
                    $value = (int) $value;
                    $numeric = true;
                    break;
                case 'decimal':
                    $s = is_string($value) ? str_replace([',', ' '], '', $value) : $value;
                    if (!is_numeric($s) || !preg_match('/^-?\d{1,10}(\.\d{1,2})?$/', (string) $s)) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون مبلغاً صحيحاً بحد أقصى رقمين عشريين");
                    }
                    $value = number_format((float) $s, 2, '.', '');
                    $numeric = true;
                    break;
                case 'bool':
                    $b = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                    if ($b === null) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون صح أو خطأ");
                    }
                    $value = $b;
                    break;
                case 'email':
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون بريداً إلكترونياً صحيحاً");
                    }
                    $value = strtolower((string) $value);
                    break;
                case 'phone':
                    $p = self::normalizePhone((string) $value, $this->countryCode);
                    if ($p === null) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون رقم هاتف صحيحاً");
                    }
                    $value = $p;
                    break;
                case 'uuid':
                    if (!Uuid::isValid((string) $value)) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون معرفاً صحيحاً");
                    }
                    $value = strtolower((string) $value);
                    break;
                case 'datetime':
                    try {
                        $value = $this->clock->toDb((string) $value);
                    } catch (\Exception) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون تاريخاً ووقتاً صحيحاً");
                    }
                    break;
                case 'date':
                    $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
                    if ($d === false || $d->format('Y-m-d') !== (string) $value) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون تاريخاً بصيغة YYYY-MM-DD");
                    }
                    break;
                case 'in':
                    if (!in_array((string) $value, explode(',', (string) $arg), true)) {
                        throw new \InvalidArgumentException("قيمة الحقل {$label} غير مسموحة");
                    }
                    $value = (string) $value;
                    break;
                case 'array':
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون قائمة");
                    }
                    break;
                case 'url':
                    if (filter_var($value, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', (string) $value)) {
                        throw new \InvalidArgumentException("الحقل {$label} يجب أن يكون رابطاً صحيحاً");
                    }
                    break;
                default:
                    throw new \LogicException('قاعدة تحقق غير معروفة: ' . $name);
            }
        }
        return $value;
    }

    /**
     * توحيد رقم الهاتف بصيغة E.164 الدولية (+967...).
     */
    public static function normalizePhone(string $raw, string $countryCode = '967'): ?string
    {
        $raw = strtr($raw, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $digits = preg_replace('/[\s\-\(\)\.]/', '', $raw) ?? '';
        if (str_starts_with($digits, '+')) {
            $digits = substr($digits, 1);
        } elseif (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $countryCode . substr($digits, 1);
        } elseif (strlen($digits) <= 9) {
            $digits = $countryCode . $digits;
        }
        if (!preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }
        return '+' . $digits;
    }
}
