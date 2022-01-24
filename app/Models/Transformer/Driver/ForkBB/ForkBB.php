<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Transformer\Driver\ForkBB;

use ForkBB\Core\DB;
use ForkBB\Models\Transformer\Driver\AbstractDriver;
use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

class ForkBB extends AbstractDriver
{
    /**
     * @var array
     */
    protected $reqTables = [
        'bans',
        'bbcode',
        'categories',
        'censoring',
        'config',
        'forums',
        'forum_perms',
        'forum_subscriptions',
        'groups',
        'mark_of_forum',
        'mark_of_topic',
        'online',
        'pm_block',
        'pm_posts',
        'pm_topics',
        'poll',
        'poll_voted',
        'posts',
        'reports',
        'search_cache',
        'search_matches',
        'search_words',
        'smilies',
        'topics',
        'topic_subscriptions',
        'users',
        'warnings',
    ];

    public function getType(): string
    {
        return 'ForkBB';
    }

    public function test(DB $db) /* : bool|string|array */
    {
        if (! $this->reqTablesTest($db)) {
            if (! empty($this->error)) {
                return $this->error;
            }

            return false;
        }

        $query = 'SELECT conf_value
            FROM ::config
            WHERE conf_name=\'i_fork_revision\'';
        $rev   = $db->query($query)->fetchColumn();

        if (
            null === $rev
            || ! \is_numeric($rev)
            || ! \is_int($rev + 0)
        ) {
            return false;
        } else {
            $rev = (int) $rev;
        }

        if ($rev !== $this->c->FORK_REVISION) {
            $rev         = 'rev.' . $rev;
            $need        = 'rev.' . $this->c->FORK_REVISION;

            return ['Current version \'%1$s\' is %2$s, need %3$s to %4$s', $this->getType(), $rev, $need, $need];
        }

        return true;
    }

    /*************************************************************************/
    /* categories                                                            */
    /*************************************************************************/
    public function categoriesPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['categories'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::categories ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::categories
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function categoriesGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function categoriesSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function categoriesEnd(DB $db): bool
    {
        $query = 'SELECT MAX(disp_position)
            FROM ::categories
            WHERE id_old=0';

        $max = (int) $db->query($query)->fetchColumn();

        if ($max < 1) {
            return true;
        }

        $vars = [
            ':max' => $max,
        ];
        $query = 'UPDATE ::categories
            SET disp_position = disp_position + ?i:max
            WHERE id_old>0';

        return false !== $db->exec($query, $row);
    }

    /*************************************************************************/
    /* groups                                                                */
    /*************************************************************************/
    public function groupsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['groups'];

        unset($map['g_id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::groups ({$sub['fields']}) VALUES ({$sub['values']})";

        unset($map['id_old']); // дефолтовые группы не меняются для ForkBB->ForkBB

        $sub               = $this->subQUpdate($map);
        $this->updateQuery = "UPDATE ::groups SET {$sub['sets']} WHERE g_id=?i:id_old";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::groups
            WHERE g_id>=?i:id
            ORDER BY g_id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function groupsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['g_id'];
        $row['id_old'] = $id;

        unset($row['g_id']);

        return $row;
    }

    public function groupsSet(DB $db, array $row): bool
    {
        if (isset($this->defGroups[$row['id_old']])) {
            if (TRANSFORMER_MERGE === $this->c->TR_METHOD) {
                return true;
            }

            $query = $this->updateQuery;
        } else {

            $query = $this->insertQuery;
        }

        return false !== $db->exec($query, $row);
    }

    public function groupsEnd(DB $db): bool
    {
        $query = 'SELECT a.g_promote_next_group AS old, b.g_id AS new
            FROM ::groups AS a
            INNER JOIN ::groups AS b ON b.id_old=a.g_promote_next_group
            WHERE a.id_old>0 AND a.g_id>4 AND a.g_promote_next_group>0';

        $stmt = $db->query($query);

        $query = 'UPDATE ::groups
            SET g_promote_next_group=?i:new
            WHERE id_old>0 AND g_id>4 AND g_promote_next_group=?i:old';

        while ($vars = $stmt->fetch()) {
            if (false === $db->exec($query, $vars)) {
                return false;
            }
        }

        return true;
    }

    /*************************************************************************/
    /* users                                                                 */
    /*************************************************************************/
    public function usersPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['users'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::users ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
            ':unv'   => FORK_GROUP_UNVERIFIED,
            ':guest' => FORK_GROUP_GUEST,
        ];
        $query = 'SELECT *
            FROM ::users
            WHERE id>=?i:id AND group_id!=?i:unv AND group_id!=?i:guest
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function usersGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function usersSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function usersEnd(DB $db): bool
    {
        $query = 'UPDATE ::users
            SET group_id=COALESCE(
                (
                    SELECT g.g_id
                    FROM ::groups AS g
                    WHERE g.id_old=group_id
                ),
                group_id
            )
            WHERE id_old>0';

        return false !== $db->exec($query);
    }

    /*************************************************************************/
    /* forums                                                                */
    /*************************************************************************/
    public function forumsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['forums'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::forums ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::forums
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function forumsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function forumsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function forumsEnd(DB $db): bool
    {
        $query = 'SELECT MAX(disp_position)
            FROM ::forums
            WHERE id_old=0';

        $max = (int) $db->query($query)->fetchColumn();

        if ($max > 0) {
            $vars = [
                ':max' => $max,
            ];
            $query = 'UPDATE ::forums
                SET disp_position = disp_position + ?i:max
                WHERE id_old>0';

            if (false === $db->exec($query, $row)) {
                return false;
            }
        }

        $query = 'UPDATE ::forums
            SET cat_id=(
                SELECT c.id
                FROM ::categories AS c
                WHERE c.id_old=cat_id
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'SELECT f.parent_forum_id, o.id
            FROM ::forums AS f
            INNER JOIN ::forums AS o ON o.id_old=f.parent_forum_id
            WHERE f.id_old>0 AND f.parent_forum_id>0';

        $stmt = $db->query($query);

        $query = 'UPDATE ::forums
            SET parent_forum_id=?i:id
            WHERE id_old>0 AND parent_forum_id=?i:parent_forum_id';

        while ($row = $stmt->fetch()) {
            if (false === $db->exec($query, $row)) {
                return false;
            }
        }

        $query = 'SELECT id, moderators
            FROM ::forums
            WHERE id_old>0 AND moderators!=\'\'';

        $data = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($data as $id => $mods) {
            $moderators = \json_decode($mods, true);

            if (\is_array($moderators)) {
                $vars = [
                    ':ids' => \array_keys($moderators),
                ];
                $query = 'SELECT id_old, id
                    FROM ::users
                    WHERE id_old IN (?ai:ids)';

                $repl = $db->query($query, $vars)->fetchAll(PDO::FETCH_KEY_PAIR);
                $mods = [];

                foreach ($moderators as $old => $name) {
                    $mods[$repl[$old]] = $name;
                }

                $mods = \json_encode($mods);
            } else {
                $mods = '';
            }

            $vars = [
                ':id'   => $id,
                ':mods' => $mods,
            ];
            $query = 'UPDATE ::forums
                SET moderators=?s:mods
                WHERE id=?i:id';

            if (false === $db->exec($query, $vars)) {
                return false;
            }
        }

        return true;
    }

    /*************************************************************************/
    /* forum_perms                                                           */
    /*************************************************************************/
    public function forum_permsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['forum_perms'];

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::forum_perms ({$sub['fields']}) VALUES ({$sub['values']})";

        $query = 'SELECT *
            FROM ::forum_perms';

        $this->stmt = $db->query($query);

        return false !== $this->stmt;
    }

    public function forum_permsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        return $row;
    }

    public function forum_permsSet(DB $db, array $row): bool
    {
        if (null === $this->replGroups) {
            $query = 'SELECT id_old, g_id
                FROM ::groups
                WHERE id_old>0';

            $this->replGroups = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);

            $query = 'SELECT id_old, id
                FROM ::forums
                WHERE id_old>0';

            $this->replForums = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        $row['group_id'] = $this->replGroups[$row['group_id']] ?? $row['group_id'];
        $row['forum_id'] = $this->replForums[$row['forum_id']];

        return false !== $db->exec($this->insertQuery, $row);
    }

    public function forum_permsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* bbcode                                                                */
    /*************************************************************************/
    public function bbcodePre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['bbcode'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::bbcode ({$sub['fields']}) VALUES ({$sub['values']})";

        unset($map['bb_tag']);

        $sub               = $this->subQUpdate($map);
        $this->updateQuery = "UPDATE ::bbcode SET {$sub['sets']} WHERE bb_tag=?s:bb_tag";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::bbcode
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function bbcodeGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $row['id'];

        unset($row['id']);

        return $row;
    }

    public function bbcodeSet(DB $db, array $row): bool
    {
        try {
            return false !== $db->exec($this->insertQuery, $row);
        } catch (PDOException $e) {
            return false !== $db->exec($this->updateQuery, $row);
        }
    }

    public function bbcodeEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* censoring                                                             */
    /*************************************************************************/
    public function censoringPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['censoring'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::censoring ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::censoring
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function censoringGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $row['id'];

        unset($row['id']);

        return $row;
    }

    public function censoringSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function censoringEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* smilies                                                               */
    /*************************************************************************/
    public function smiliesPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['smilies'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::smilies ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::smilies
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function smiliesGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $row['id'];

        unset($row['id']);

        return $row;
    }

    public function smiliesSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function smiliesEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* topics                                                                */
    /*************************************************************************/
    public function topicsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['topics'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::topics ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::topics
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function topicsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function topicsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function topicsEnd(DB $db): bool
    {
        $query = 'UPDATE ::topics
            SET forum_id=(
                SELECT f.id
                FROM ::forums AS f
                WHERE f.id_old=forum_id
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=poster_id
                ),
                0
            )
            WHERE id_old>0 AND poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET last_poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=last_poster_id
                ),
                0
            )
            WHERE id_old>0 AND last_poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'SELECT t.id, o.id AS moved_to
            FROM ::topics AS t
            LEFT JOIN ::topics AS o ON o.id_old=t.moved_to
            WHERE t.id_old>0 AND t.moved_to>0';

        $stmt = $db->query($query);

        $query = 'UPDATE ::topics
            SET moved_to=?i:moved_to
            WHERE id=?i:id';

        while ($row = $stmt->fetch()) {
            if (false === $db->exec($query, $row)) {
                return false;
            }
        }

        return true;
    }

    /*************************************************************************/
    /* posts                                                                 */
    /*************************************************************************/
    public function postsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['posts'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::posts ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::posts
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function postsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function postsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function postsEnd(DB $db): bool
    {
        $query = 'UPDATE ::posts
            SET topic_id=(
                SELECT t.id
                FROM ::topics AS t
                WHERE t.id_old=topic_id
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::posts
            SET poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=poster_id
                ),
                0
            )
            WHERE id_old>0 AND poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::posts
            SET editor_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=editor_id
                ),
                0
            )
            WHERE id_old>0 AND editor_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET first_post_id=COALESCE(
                (
                    SELECT p.id
                    FROM ::posts AS p
                    WHERE p.id_old=first_post_id
                ),
                0
            )
            WHERE id_old>0 AND first_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET last_post_id=COALESCE(
                (
                    SELECT p.id
                    FROM ::posts AS p
                    WHERE p.id_old=last_post_id
                ),
                0
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::forums
            SET last_post_id=COALESCE(
                (
                    SELECT p.id
                    FROM ::posts AS p
                    WHERE p.id_old=last_post_id
                ),
                0
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* warnings                                                              */
    /*************************************************************************/
    public function warningsPre(DB $db, int $id): bool
    {
        return true;
    }

    public function warningsGet(int &$id): ?array
    {
        return null;
    }

    public function warningsSet(DB $db, array $row): bool
    {
        return true;
    }

    public function warningsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* reports                                                               */
    /*************************************************************************/
    public function reportsPre(DB $db, int $id): bool
    {
        return true;
    }

    public function reportsGet(int &$id): ?array
    {
        return null;
    }

    public function reportsSet(DB $db, array $row): bool
    {
        return true;
    }

    public function reportsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* forum_subscriptions                                                   */
    /*************************************************************************/
    public function forum_subscriptionsPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT user_id
            FROM ::forum_subscriptions
            WHERE user_id>=?i:id
            ORDER BY user_id
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::forum_subscriptions (user_id, forum_id)
            SELECT (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:user_id
                ), (
                    SELECT id
                    FROM ::forums
                    WHERE id_old=?i:forum_id
                )';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::forum_subscriptions
            WHERE user_id>=?i:id AND user_id<=?i:max
            ORDER BY user_id';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function forum_subscriptionsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['user_id'];

        return $row;
    }

    public function forum_subscriptionsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function forum_subscriptionsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* topic_subscriptions                                                   */
    /*************************************************************************/
    public function topic_subscriptionsPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT user_id
            FROM ::topic_subscriptions
            WHERE user_id>=?i:id
            ORDER BY user_id
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::topic_subscriptions (user_id, topic_id)
            SELECT (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:user_id
                ), (
                    SELECT id
                    FROM ::topics
                    WHERE id_old=?i:topic_id
                )';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::topic_subscriptions
            WHERE user_id>=?i:id AND user_id<=?i:max
            ORDER BY user_id';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function topic_subscriptionsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['user_id'];

        return $row;
    }

    public function topic_subscriptionsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function topic_subscriptionsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* mark_of_forum                                                         */
    /*************************************************************************/
    public function mark_of_forumPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT uid
            FROM ::mark_of_forum
            WHERE uid>=?i:id
            ORDER BY uid
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::mark_of_forum (uid, fid, mf_mark_all_read)
            SELECT (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:uid
                ), (
                    SELECT id
                    FROM ::forums
                    WHERE id_old=?i:fid
                ),
                ?i:mf_mark_all_read';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::mark_of_forum
            WHERE uid>=?i:id AND uid<=?i:max
            ORDER BY uid';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function mark_of_forumGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['uid'];

        return $row;
    }

    public function mark_of_forumSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function mark_of_forumEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* mark_of_topic                                                         */
    /*************************************************************************/
    public function mark_of_topicPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT uid
            FROM ::mark_of_topic
            WHERE uid>=?i:id
            ORDER BY uid
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::mark_of_topic (uid, tid, mt_last_visit, mt_last_read)
            SELECT (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:uid
                ), (
                    SELECT id
                    FROM ::topics
                    WHERE id_old=?i:tid
                ),
                ?i:mt_last_visit, ?i:mt_last_read';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::mark_of_topic
            WHERE uid>=?i:id AND uid<=?i:max
            ORDER BY uid';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function mark_of_topicGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['uid'];

        return $row;
    }

    public function mark_of_topicSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function mark_of_topicEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* poll                                                                  */
    /*************************************************************************/
    public function pollPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT tid
            FROM ::poll
            WHERE tid>=?i:id
            ORDER BY tid
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::poll (tid, question_id, field_id, qna_text, votes)
            SELECT (
                    SELECT id
                    FROM ::topics
                    WHERE id_old=?i:tid
                ),
                ?i:question_id, ?i:field_id, ?s:qna_text, ?i:votes';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::poll
            WHERE tid>=?i:id AND tid<=?i:max
            ORDER BY tid';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function pollGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['tid'];

        return $row;
    }

    public function pollSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function pollEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* poll_voted                                                            */
    /*************************************************************************/
    public function poll_votedPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT tid
            FROM ::poll_voted
            WHERE tid>=?i:id
            ORDER BY tid
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::poll_voted (tid, uid, rez)
            SELECT (
                    SELECT id
                    FROM ::topics
                    WHERE id_old=?i:tid
                ), (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:uid
                ),
                ?s:rez';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::poll_voted
            WHERE tid>=?i:id AND tid<=?i:max
            ORDER BY tid';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function poll_votedGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['tid'];

        return $row;
    }

    public function poll_votedSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function poll_votedEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* pm_topics                                                             */
    /*************************************************************************/
    public function pm_topicsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['pm_topics'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::pm_topics ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::pm_topics
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function pm_topicsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function pm_topicsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function pm_topicsEnd(DB $db): bool
    {
        $query = 'UPDATE ::pm_topics
            SET poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=poster_id
                ),
                0
            )
            WHERE id_old>0 AND poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::pm_topics
            SET target_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=target_id
                ),
                0
            )
            WHERE id_old>0 AND target_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* pm_posts                                                              */
    /*************************************************************************/
    public function pm_postsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['pm_posts'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::pm_posts ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::pm_posts
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function pm_postsGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id            = (int) $row['id'];
        $row['id_old'] = $id;

        unset($row['id']);

        return $row;
    }

    public function pm_postsSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function pm_postsEnd(DB $db): bool
    {
        $query = 'UPDATE ::pm_posts
            SET topic_id=(
                SELECT t.id
                FROM ::pm_topics AS t
                WHERE t.id_old=topic_id
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::pm_posts
            SET poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=poster_id
                ),
                0
            )
            WHERE id_old>0 AND poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::pm_topics
            SET first_post_id=COALESCE(
                (
                    SELECT p.id
                    FROM ::pm_posts AS p
                    WHERE p.id_old=first_post_id
                ),
                0
            )
            WHERE id_old>0 AND first_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::pm_topics
            SET last_post_id=COALESCE(
                (
                    SELECT p.id
                    FROM ::pm_posts AS p
                    WHERE p.id_old=last_post_id
                ),
                0
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* pm_block                                                              */
    /*************************************************************************/
    public function pm_blockPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT bl_first_id
            FROM ::pm_block
            WHERE bl_first_id>=?i:id
            ORDER BY bl_first_id
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::pm_block (bl_first_id, bl_second_id)
            SELECT (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:bl_first_id
                ), (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:bl_second_id
                )';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::pm_block
            WHERE bl_first_id>=?i:id AND bl_first_id<=?i:max
            ORDER BY bl_first_id';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function pm_blockGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['bl_first_id'];

        return $row;
    }

    public function pm_blockSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function pm_blockEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* bans                                                                  */
    /*************************************************************************/
    public function bansPre(DB $db, int $id): bool
    {
        $this->insertQuery = 'INSERT INTO ::bans (username, ip, email, message, expire, ban_creator)
            SELECT ?s:username, ?s:ip, ?s:email, ?s:message, ?i:expire, (
                    SELECT id
                    FROM ::users
                    WHERE id_old=?i:ban_creator
                )';

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::bans
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function bansGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $row['id'];

        return $row;
    }

    public function bansSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function bansEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* config                                                                */
    /*************************************************************************/
    public function configPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['config'];

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::config ({$sub['fields']}) VALUES ({$sub['values']})";

        $query = 'SELECT *
            FROM ::config';

        $this->stmt = $db->query($query);

        return false !== $this->stmt;
    }

    public function configGet(int &$id): ?array
    {
        $row = $this->stmt->fetch();

        if (false === $row) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        return $row;
    }

    public function configSet(DB $db, array $row): bool
    {
        return false !== $db->exec($this->insertQuery, $row);
    }

    public function configEnd(DB $db): bool
    {
        return true;
    }
}