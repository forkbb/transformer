<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\User;

use ForkBB\Models\Action;
use ForkBB\Models\Group\Group;

class UsersNumber extends Action
{
    /**
     * Подсчет количества пользователей в группе
     */
    public function usersNumber(Group $group): int
    {
        if (
            empty($group->g_id)
            || $group->g_id === FORK_GROUP_GUEST
        ) {
            return 0;
        }

        $vars = [
            ':gid' => $group->g_id,
        ];
        $query = 'SELECT COUNT(u.id)
            FROM ::users AS u
            WHERE u.group_id=?i:gid';

        return (int) $this->c->DB->query($query, $vars)->fetchColumn();
    }
}
