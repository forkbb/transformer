<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Core;

use ForkBB\Core\Container;
use ForkBB\Core\File;
use ForkBB\Core\RulesValidator;
use InvalidArgumentException;
use RuntimeException;
use function \ForkBB\__;

class Validator
{
    /**
     * Массив валидаторов
     */
    protected array $validators;

    /**
     * Массив правил для текущей проверки данных
     */
    protected array $rules;

    /**
     * Массив результатов проверенных данных
     */
    protected array $result;

    /**
     * Массив дополнительных аргументов для валидаторов и конкретных полей/правил
     */
    protected array $arguments;

    /**
     * Массив сообщений об ошибках для конкретных полей/правил
     */
    protected array $messages;

    /**
     * Массив псевдонимов имен полей для вывода в ошибках
     */
    protected array $aliases;

    /**
     * Массив ошибок валидации
     */
    protected array $errors;

    /**
     * Массив имен полей для обработки
     */
    protected array $fields;

    /**
     * Массив состояний проверки полей
     */
    protected array $status;

    /**
     * Массив входящих данных для обработки
     */
    protected ?array $raw;

    /**
     * Данные для текущей обработки
     */
    protected array $curData;

    /**
     * Флаг ошибки
     */
    protected ?bool $error = null;

    public function __construct(protected Container $c)
    {
        $this->reset();
    }

    /**
     * Сбрасывает настройки к начальным состояниям
     */
    public function reset(): Validator
    {
        $this->validators = [
            'absent'        => [$this, 'vAbsent'],
            'array'         => [$this, 'vArray'],
            'checkbox'      => [$this, 'vCheckbox'],
            'date'          => [$this, 'vDate'],
            'exist'         => [$this, 'vExist'],
            'file'          => [$this, 'vFile'],
            'image'         => [$this, 'vImage'],
            'in'            => [$this, 'vIn'],
            'integer'       => [$this, 'vInteger'],
            'max'           => [$this, 'vMax'],
            'min'           => [$this, 'vMin'],
            'numeric'       => [$this, 'vNumeric'],
            'not_in'        => [$this, 'vNotIn'],
            'password'      => [$this, 'vPassword'],
            'referer'       => [$this, 'vReferer'],
            'regex'         => [$this, 'vRegex'],
            'required'      => [$this, 'vRequired'],
            'required_with' => [$this, 'vRequiredWith'],
            'same'          => [$this, 'vSame'],
            'string'        => [$this, 'vString'],
            'token'         => [$this, 'vToken'],
        ];
        $this->rules     = [];
        $this->result    = [];
        $this->arguments = [];
        $this->messages  = [];
        $this->aliases   = [];
        $this->errors    = [];
        $this->fields    = [];
        $this->status    = [];

        return $this;
    }

    /**
     * Добавляет валидаторы
     */
    public function addValidators(array $validators): Validator
    {
        $this->validators = \array_replace($this->validators, $validators);

        return $this;
    }

    /**
     * Добавляет правила
     */
    public function addRules(array $list): Validator
    {
        foreach ($list as $field => $raw) {
            $rules = [];

            if (! \is_array($raw)) {
                $raw = \explode('|', $raw);
            }

            // перебор правил для текущего поля
            foreach ($raw as $name => $rule) {
                if (! \is_string($name)) {
                    list($name, $rule) = \array_pad(\explode(':', $rule, 2), 2, '');
                }

                if (empty($this->validators[$name])) {
                    try {
                        $validator = $this->c->{"VL{$name}"};
                    } catch (Exception $e) {
                        $validator = null;
                    }

                    if ($validator instanceof RulesValidator) {
                        $this->validators[$name] = [$validator, $name];

                    } else {
                        throw new RuntimeException("{$name} validator not found");
                    }
                }

                if (
                    'array' === $name
                    && ! \is_array($rule)
                ) {
                    $rule = [];
                }

                $rules[$name] = $rule ?? '';
            }

            if (\strpos($field, '.') > 0) {
                $fields = \explode('.', $field);
                $n      = \count($fields);
                $start  = true;
                $r      = &$this->rules;

                foreach ($fields as $field) {
                    if (true === $start) {
                        $this->fields[$field] = $field;
                        $start                = false;
                    }

                    if (--$n) {
                        if (! isset($r[$field]['array'])) {
                            $r[$field]['array'] = [];
                        }

                        $r = &$r[$field]['array'];

                    } else {
                        $r[$field] = $rules;
                    }
                }

                unset ($r);

            } else {
                $this->rules[$field]  = $rules;
                $this->fields[$field] = $field;
            }
        }

        return $this;
    }

    /**
     * Добавляет дополнительные аргументы для конкретных "имя поля"."имя правила".
     */
    public function addArguments(array $arguments): Validator
    {
        $this->arguments = \array_replace($this->arguments, $arguments);

        return $this;
    }

    /**
     * Добавляет сообщения для конкретных "имя поля"."имя правила".
     */
    public function addMessages(array $messages): Validator
    {
        $this->messages = \array_replace($this->messages, $messages);

        return $this;
    }

    /**
     * Добавляет псевдонимы имен полей для сообщений об ошибках
     */
    public function addAliases(array $aliases): Validator
    {
        $this->aliases = \array_replace($this->aliases, $aliases);

        return $this;
    }

    /**
     * Проверяет данные
     */
    public function validation(array $raw, bool $strict = false): bool
    {
        if (empty($this->rules)) {
            throw new RuntimeException('Rules not found');
        }

        $this->errors  = [];
        $this->status  = [];
        $this->curData = [];
        $this->raw     = $raw;

        foreach ($this->fields as $field) {
            $this->__get($field);
        }

        if (
            $strict
            && empty($this->errors)
            && ! empty(\array_diff_key($this->raw, $this->fields))
        ) {
            $this->addError('Too much data');
        }

        $this->raw = null;

        return empty($this->errors);
    }

    /**
     * Проверяет наличие поля
     */
    public function __isset(string $field): bool
    {
        return isset($this->result[$field]);
    }

    /**
     * Проверяет поле согласно заданным правилам
     * Возвращает значение запрашиваемого поля
     */
    public function __get(string $field): mixed
    {
        if (isset($this->status[$field])) {
            return $this->result[$field];

        } elseif (empty($this->rules[$field])) {
            throw new RuntimeException("No rules for '{$field}' field");
        }

        if (isset($this->raw[$field])) {
            $value = $this->c->Secury->replInvalidChars($this->raw[$field]);

        } else {
            $value = null;
        }

        if (
            null === $value
            && isset($this->rules[$field]['required'])
        ) {
            $rules = ['required' => ''];

        } else {
            $rules = $this->rules[$field];
        }

        $value = $this->checkValue($value, $rules, $field);

        $this->status[$field] = true !== $this->error; // в $this->error может быть состояние false
        $this->result[$field] = $value;

        return $value;
    }

    /**
     * Проверяет значение списком правил
     */
    protected function checkValue(mixed $value, array $rules, string $field): mixed
    {
        foreach ($rules as $validator => $attr) {
            // данные для обработчика ошибок
            $this->error     = null;
            $this->curData[] = [
                'field' => $field,
                'rule'  => $validator,
                'attr'  => $attr,
            ];

            $value = $this->validators[$validator]($this, $value, $attr, $this->getArguments($field, $validator));

            \array_pop($this->curData);

            if (null !== $this->error) {
                break;
            }
        }

        return $value;
    }

    /**
     * Добавляет ошибку
     */
    public function addError(string|array|null $error, string $type = FORK_MESS_VLD): void
    {
        if (empty($vars = \end($this->curData))) {
            throw new RuntimeException('The array of variables is empty');
        }

        // нет ошибки, для выхода из цикла проверки правил
        if (null === $error) {
            $this->error = false;

            return;
        }

        \extract($vars);

        $alias   = $this->aliases[$field] ?? $field;
        $message = $this->messages["{$field}.{$rule}"]
            ?? $this->messages[$field]
                ?? (\is_string($error) ? $error : null);

        if (isset($message)) {
            if (\is_array($message)) {
                list($type, $message) = $message;
            }

            if (\is_array($attr)) {
                $attr = \implode(',', $attr);
            }

            $this->errors[$type][] = \is_array($message)
                ? $message
                : [$message, [':alias' => __($alias), ':attr' => $attr]];

        } elseif (\is_array($error)) {
            $this->errors[$type][] = $error;

        } else {
            throw new InvalidArgumentException('Expected string or array');
        }

        $this->error = true;
    }

    /**
     * Возвращает дополнительные аргументы
     */
    protected function getArguments(string $field, string $rule): mixed
    {
        return $this->arguments["{$field}.{$rule}"] ?? $this->arguments[$field] ?? null;
    }

    /**
     * Возвращает статус проверки поля
     */
    public function getStatus(string $field): bool
    {
        if (! isset($this->status[$field])) {
            $this->__get($field);
        }

        return $this->status[$field];
    }

    /**
     * Возвращает проверенные данные
     * Поля с ошибками содержат значения по умолчанию или значения с ошибками
     */
    public function getData(bool $all = false, array $doNotUse = []): array
    {
        if (empty($this->status)) {
            throw new RuntimeException('Data not found');
        }

        if (empty($doNotUse)) {
            $result = $this->result;

        } else {
            $result = \array_diff_key($this->result, \array_flip($doNotUse));
        }

        if ($all) {
            return $result;

        } else {
            return \array_filter($result, function ($value) {
                return null !== $value;
            });
        }
    }

    /**
     * Возращает массив ошибок
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Возращает массив ошибок без разделения на типы
     */
    public function getErrorsWithoutType(): array
    {
        $result = [];

        foreach ($this->errors as $errors) {
            \array_push($result, ...$errors);
        }

        return $result;
    }

    /**
     * Удаляет пробельные символы UTF-8 по краям строки
     */
    public function trim(string $value): string
    {
        return \preg_replace('%^\s+|\s+$%u', '', $value);
    }

    /**
     * Проверяет переменную на отсутсвие содержимого
     */
    public function noValue(mixed $value, bool $withArray = false): bool
    {
        if (null === $value) {
            return true;

        } elseif (\is_string($value)) {
            return '' === $this->trim($value);

        } elseif (\is_array($value)) {
            return $withArray && empty($value);

        } else {
            return false;
        }
    }

    /**
     * Выполняет проверку значения по правилу
     *
     * @param Validator $first   ссылка на валидатор
     * @param mixed $second      проверяемое значение
     * @param mixed $third       атрибут правила
     * @param mixed $fourth      дополнительный аргумент
     *
     * @return mixed
     */
    protected function vAbsent(Validator $v, mixed $value, string $attr): mixed
    {
        if (null === $value) {
            if (isset($attr[0])) {
                $value = $attr;
            }

        } else {
            $this->addError('The :alias should be absent');
        }

        return $value;
    }

    protected function vExist(Validator $v, mixed $value): mixed
    {
        if (null === $value) {
            $this->addError('The :alias not exist');
        }

        return $value;
    }

    protected function vRequired(Validator $v, mixed $value): mixed
    {
        if ($this->noValue($value, true)) {
            $this->addError('The :alias is required');

            $value = null;
        }

        return $value;
    }

    protected function vRequiredWith(Validator $v, mixed $value, string $attr): mixed
    {
        foreach (\explode(',', $attr) as $field) {
            if (null !== $this->__get($field)) {     // если есть хотя бы одно поле,
                return $this->vRequired($v, $value); // то проверяем данное поле
            }                                        // на обязательное наличие
        }

        if (null === $value) {                       // если данное поле отсутствует,
            $this->addError(null);                   // то прерываем его проверку
        }

        return $value;
    }

    protected function vString(Validator $v, mixed $value, string $attr): ?string
    {
        if (\is_string($value)) {
            if (isset($attr[0])) {
                foreach (\explode(',', $attr) as $action) {
                    switch ($action) {
                        case 'trim':
                            $value = $this->trim($value);

                            break;
                        case 'lower':
                            $value = \mb_strtolower($value, 'UTF-8');

                            break;
                        case 'spaces':
                            $value = \preg_replace('%\s+%u', ' ', $value);

                            break;
                        case 'linebreaks':
                            $value = \str_replace(["\r\n", "\r"], "\n", $value);

                            break;
                        case 'null':
                            if ('' === $value) {
                                $value = null;

                                $this->addError(null);

                                break 2;
                            }

                            break;
                        case 'empty':
                            if ('' === $value) {
                                $this->addError(null);

                                break 2;
                            }

                            break;
                        default:
                            throw new InvalidArgumentException("Bad action: {$action}");
                    }
                }
            }

        } elseif (null === $value) {
            $this->addError(null);

        } else {
            $this->addError('The :alias must be string');

            $value = \is_scalar($value) ? (string) $value : null;
        }

        return $value;
    }

    protected function vNumeric(Validator $v, mixed $value): mixed
    {
        if (\is_numeric($value)) {
            $value += 0;

        } elseif (
            null === $value
            || '' === $value
        ) {
            $this->addError(null);

            $value = null;

        } else {
            $this->addError('The :alias must be numeric');

            $value = \is_scalar($value) ? (string) $value : null;
        }

        return $value;
    }

    protected function vInteger(Validator $v, mixed $value): mixed
    {
        if (
            \is_numeric($value)
            && \is_int(0 + $value)
        ) {
            $value += 0;

        } elseif (
            null === $value
            || '' === $value
        ) {
            $this->addError(null);

            $value = null;

        } else {
            $this->addError('The :alias must be integer');

            $value = \is_scalar($value) ? (string) $value : null;
        }

        return $value;
    }

    protected function vArray(Validator $v, mixed $value, array $attr): ?array
    {
        if (
            null !== $value
            && ! \is_array($value)
        ) {
            $this->addError('The :alias must be array');

            return null;

        } elseif (! $attr) {
            return $value;
        }

        if (empty($vars = \end($this->curData))) {
            throw new RuntimeException('The array of variables is empty');
        }

        $result = [];

        foreach ($attr as $name => $rules) {
            $this->recArray($value, $result, $name, $rules, "{$vars['field']}.{$name}");
        }

        return $result;
    }

    protected function recArray(&$value, &$result, $name, $rules, $field)
    {
        if ('' === $name) {
            $result = $this->checkValue($value, $rules, $field);

        } else {
            if (false !== \strpos((string) $name, '.')) {
                throw new RuntimeException("Bad path '{$name}'");
            }

            if (
                '*' === $name
                && \is_array($value)
            ) {
                foreach ($value as $i => $cur) {
                    $this->recArray($value[$i], $result[$i], '', $rules, $field);
                }

            } elseif (
                '*' !== $name
                && \is_array($value)
                && \array_key_exists($name, $value)
            ) {
                $this->recArray($value[$name], $result[$name], '', $rules, $field);

            } elseif (isset($rules['required'])) {
                $tmp1 = null;
                $tmp2 = null;

                $this->recArray($tmp1, $tmp2, '', $rules, $field);

            } elseif ('*' === $name) {
                $result = []; // ???? а может там не отсутствие элемента, а не array?

            } else {
                $value[$name] = null;

                $this->recArray($value[$name], $result[$name], '', $rules, $field);
            }
        }
    }

    protected function vMin(Validator $v, mixed $value, string $attr): mixed
    {
        if (! \preg_match('%^(-?\d+)(\s*bytes)?$%i', $attr, $matches)) {
            throw new InvalidArgumentException('Expected number in attribute');
        }

        $min     = (int) $matches[1];
        $inBytes = ! empty($matches[2]);

        if (\is_string($value)) {
            if (
                (
                    $inBytes
                    && \strlen($value) < $min
                )
                || (
                    ! $inBytes
                    && \mb_strlen($value, 'UTF-8') < $min
                )
            ) {
                $this->addError('The :alias minimum is :attr characters');
            }

        } elseif (\is_numeric($value)) {
            if (0 + $value < $min) {
                $this->addError('The :alias minimum is :attr');
            }

        } elseif (\is_array($value)) {
            if (\count($value) < $min) {
                $this->addError('The :alias minimum is :attr elements');
            }

        } else {
            $this->addError('The :alias minimum is :attr');

            $value = null;
        }

        return $value;
    }

    protected function vMax(Validator $v, mixed $value, string $attr): mixed
    {
        if (! \preg_match('%^(-?\d+)(\s*bytes)?$%i', $attr, $matches)) {
            throw new InvalidArgumentException('Expected number in attribute');
        }

        $max     = (int) $matches[1];
        $inBytes = ! empty($matches[2]);

        if (\is_string($value)) {
            if (
                (
                    $inBytes
                    && \strlen($value) > $max
                )
                || (
                    ! $inBytes
                    && \mb_strlen($value, 'UTF-8') > $max
                )
            ) {
                $this->addError('The :alias maximum is :attr characters');
            }

        } elseif (\is_numeric($value)) {
            if (0 + $value > $max) {
                $this->addError('The :alias maximum is :attr');
            }

        } elseif (\is_array($value)) {
            if (\reset($value) instanceof File) {
                foreach ($value as $file) {
                    if ($file->size() > $max * 1024) {
                        $this->addError('The :alias contains too large a file');

                        $value = null;

                        break;
                    }
                }

            } elseif (\count($value) > $max) {
                $this->addError('The :alias maximum is :attr elements');
            }

        } elseif ($value instanceof File) {
            if ($value->size() > $max * 1024) {
                $this->addError('The :alias contains too large a file');

                $value = null;
            }

        } else {
            $this->addError('The :alias maximum is :attr');

            $value = null;
        }

        return $value;
    }

    protected function vToken(Validator $v, mixed $value, string $attr, mixed $args): ?string
    {
        if (! \is_array($args)) {
            $args = [];
        }

        if (\preg_match('%^([1-9]\d+):(.+)$%', $attr, $matches)) {
            $lifetime = (int) $matches[1];
            $attr     = $matches[2];

        } else {
            $lifetime = null;
        }

        if (
            ! \is_string($value)
            || ! $this->c->Csrf->verify($value, $attr, $args, $lifetime)
        ) {
            $this->addError($this->c->Csrf->getError() ?? 'Bad token', FORK_MESS_ERR);

            $value = null;
        }

        return $value;
    }

    protected function vCheckbox(Validator $v, mixed $value): mixed
    {
        if (null === $value) {
            $this->addError(null);

            $value = false;

        } elseif (\is_scalar($value)) {
            $value = (string) $value;

        } else {
            $this->addError('The :alias contains an invalid value');

            $value = null;
        }

        return $value;
    }

    protected function vReferer(Validator $v, mixed $value, string $attr, mixed $args): string
    {
        if (! \is_array($args)) {
            $args = [];
        }

        return $this->c->Router->validate($value, $attr, $args);
    }

    protected function vSame(Validator $v, mixed $value, string $attr): mixed
    {
        if (
            $this->getStatus($attr)
            && $value !== $this->__get($attr)
        ) {
            $this->addError('The :alias must be same with original');

            $value = null;
        }

        return $value;
    }

    protected function vRegex(Validator $v, string $value, string $attr): string
    {
        if (! \preg_match($attr, $value)) {
            $this->addError('The :alias is not valid format');
        }

        return $value;
    }

    protected function vPassword(Validator $v, string $value): string
    {
        return $this->vRegex($v, $value, '%[^\x20][\x20][^\x20]%');
    }

    protected function vIn(Validator $v, mixed $value, string|array $attr): mixed
    {
        if (! \is_scalar($value)) {
            $value = null;
        }

        if (
            null === $value
            || (
                \is_array($attr)
                && ! \in_array($value, $attr, true)
            )
            || (
                ! \is_array($attr)
                && ! \in_array($value, \explode(',', $attr))
            )
        ) {
            $this->addError('The :alias contains an invalid value');
        }

        return $value;
    }

    protected function vNotIn(Validator $v, mixed $value, string|array $attr): mixed
    {
        if (! \is_scalar($value)) {
            $value = null;
        }

        if (
            null === $value
            || (
                \is_array($attr)
                && \in_array($value, $attr, true)
            )
            || (
                ! \is_array($attr)
                && \in_array($value, \explode(',', $attr))
            )
        ) {
            $this->addError('The :alias contains an invalid value');
        }

        return $value;
    }


    protected function vFile(Validator $v, mixed $value, string $attr): mixed
    {
        if ($this->noValue($value, true)) {
            $this->addError(null);

            return null;
        }

        if (! \is_array($value)) {
            $this->addError('The :alias not contains file');

            return null;
        }

        $value = $this->c->Files->upload($value);

        if (null === $value) {
            $this->addError(null);

            return null;

        } elseif (false === $value) {
            $this->addError($this->c->Files->error());

            return null;

        } elseif ('multiple' === $attr) {
            if (! \is_array($value)) {
                $value = [$value];
            }

        } elseif (\is_array($value)) {
            $this->addError('The :alias contains more than one file');

            return null;
        }

        return $value;
    }

    protected function vImage(Validator $v, mixed $value, string $attr): mixed
    {
        $value = $this->vFile($v, $value, $attr);

        if (\is_array($value)) {
            foreach ($value as $file) {
                if (null === $this->c->Files->imageExt($file)) {
                    $this->addError('The :alias not contains image');

                    return null;
                }
            }

        } elseif (
            null !== $value
            && null === $this->c->Files->imageExt($value)
        ) {
            $this->addError('The :alias not contains image');

            return null;
        }

        return $value;
    }

    protected function vDate(Validator $v, mixed $value): ?string
    {
        if ($this->noValue($value)) {
            return null;
        }

        if (\is_string($value)) {
            $timestamp = $this->c->Func->dateToTime($value);

        } else {
            $timestamp = false;
        }

        if (false === $timestamp) {
            $v->addError('The :alias does not contain a date');

        } elseif ($timestamp < 0) {
            $v->addError('The :alias contains time before start of Unix');
        }

        return \is_scalar($value) ? (string) $value : null;
    }
}
