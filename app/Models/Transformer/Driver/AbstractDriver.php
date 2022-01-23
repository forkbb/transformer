<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Transformer\Driver;

use ForkBB\Core\DB;
use ForkBB\Models\Model;
use InvalidArgumentException;
use RuntimeException;

abstract class AbstractDriver extends Model
{
    /**
     * @var DB
     */
    protected $db;

    /**
     * @var DB
     */
    protected $dbSource;

    /**
     * @var array
     */
    protected $reqTables = [];

    /**
     * @var string|array;
     */
    protected $error = '';

    /**
     * @var string
     */
    protected $min = '0';

    /**
     * @var string
     */
    protected $max = '0';

    protected $defGroups = [
        FORK_GROUP_UNVERIFIED => false,
        FORK_GROUP_ADMIN      => true,
        FORK_GROUP_MOD        => true,
        FORK_GROUP_GUEST      => true,
        FORK_GROUP_MEMBER     => true,
    ];


    abstract public function getType(): string;
    abstract public function test(DB $db) /* : bool|string|array */;

    protected function reqTablesTest(DB $db): bool
    {
        $map = $db->getMap();

        if (empty($map)) {
            return false;
        }

        if (empty($this->reqTables)) {
            $this->error = ['%s does not have required tables', $this->getType()];

            return false;
        }

        foreach ($this->reqTables as $name) {
            if (! isset($map[$name])) {
                return false;
            }
        }

        return true;
    }

    protected function subQInsert(array $map): array
    {
        $fields = [];
        $values = [];

        foreach ($map as $field => $type) {
            $fields[] = $field;
            $values[] = "?{$type}:{$field}";
        }

        return [
            'fields' => \implode(', ', $fields),
            'values' => \implode(', ', $values),
        ];
    }

    protected function subQUpdate(array $map): array
    {
        $sets = [];

        foreach ($map as $field => $type) {
            $sets[] = "{$field}=?{$type}:{$field}";
        }

        return [
            'sets' => \implode(', ', $sets),
        ];
    }
}
