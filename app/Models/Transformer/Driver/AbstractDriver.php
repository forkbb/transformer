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
use PDO;
use PDOException;

abstract class AbstractDriver extends Model
{
    protected array $reqTables = [];
    protected string|array $error = '';
    protected string $min = '0';
    protected string $max = '0';
    protected array $defGroups = [
        FORK_GROUP_UNVERIFIED => false,
        FORK_GROUP_ADMIN      => true,
        FORK_GROUP_MOD        => true,
        FORK_GROUP_GUEST      => true,
        FORK_GROUP_MEMBER     => true,
    ];

    abstract public function getType(): string;
    abstract public function test(DB $db, bool $receiver = false): bool|string|array;

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

    /*************************************************************************/
    /* categories                                                            */
    /*************************************************************************/
    public function categoriesSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
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

        return false !== $db->exec($query, $vars);
    }

    /*************************************************************************/
    /* groups                                                                */
    /*************************************************************************/
    public function groupsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function groupsEnd(DB $db): bool
    {
        $query = 'SELECT a.g_promote_next_group AS old, b.g_id AS new
            FROM ::groups AS a
            INNER JOIN ::groups AS b ON b.id_old=a.g_promote_next_group
            WHERE a.id_old>0 AND a.g_id>5 AND a.g_promote_next_group>0';

        $stmt = $db->query($query);

        $query = 'UPDATE ::groups
            SET g_promote_next_group=?i:new
            WHERE id_old>0 AND g_id>5 AND g_promote_next_group=?i:old';

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
    public function usersSet(DB $db, array $vars): bool
    {
        try {
            $result = $db->exec($this->insertQuery, $vars);

            if (null !== $this->oldUsername) {
                $usernames           = $this->c->rUsernames;
                $key                 = \mb_strtolower($this->oldUsername, 'UTF-8');
                $usernames[$key]     = $vars['username'];
                $this->c->rUsernames = $usernames;

                $this->c->Log->info("[{$vars['id_old']}] username: '{$this->oldUsername}' >> '{$vars['username']}'");

                $this->oldUsername = null;
            }

            if (null !== $this->oldEmail) {
                $this->c->Log->info("[{$vars['id_old']}] email: '{$this->oldEmail}' >> '{$vars['email']}'");

                $this->oldEmail = null;
            }

            return false !== $result;
        } catch (PDOException $e) {
            if ('23000' === $e->getCode()) {
                // username
                if (false !== \strpos($e->getMessage(), 'username')) {
                    if (null === $this->oldUsername) {
                        $this->oldUsername = $vars['username'];
                    }

                    if (\preg_match('%^(.+?)\.(\d+)$%', $vars['username'], $m)) {
                        $vars['username']  = $m[1] . '.' . ($m[2] + 1);
                    } else {
                        $vars['username'] .= '.2';
                    }

                    $vars['username_normal'] = $this->c->users->normUsername($vars['username']);

                    return $this->usersSet($db, $vars);
                // email
                } elseif (false !== \strpos($e->getMessage(), 'email_normal')) {
                    if (null === $this->oldEmail) {
                        $this->oldEmail = $vars['email'];
                    }

                    if (\preg_match('%^(.+?)(?:\.n(\d+))?(\.local)$%', $vars['email'], $m)) {
                        $m[2]           = isset($m[2][0]) ? $m[2] + 1 : 2;
                        $vars['email']  = "{$m[1]}.n{$m[2]}{$m[3]}";
                    } else {
                        $vars['email'] .= '.local';
                    }

                    $vars['email_normal'] = $this->c->NormEmail->normalize($vars['email']);

                    return $this->usersSet($db, $vars);
                }
            }

            throw $e;
        }
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

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'SELECT id_old, id, username
            FROM ::users
            WHERE id_old>0
            ORDER BY id_old';

        $data = $db->query($query)->fetchAll(PDO::FETCH_UNIQUE);

        return false !== $this->c->Cache->set('repl_of_users', $data);
    }

    /*************************************************************************/
    /* forums                                                                */
    /*************************************************************************/
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

            if (false === $db->exec($query, $vars)) {
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

        $query = 'SELECT f.id, o.id AS new_parent
            FROM ::forums AS f
            LEFT JOIN ::forums AS o ON o.id_old=f.parent_forum_id
            WHERE f.id_old>0 AND f.parent_forum_id>0';

        $parents = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);;

        $query = 'UPDATE ::forums
            SET parent_forum_id=?i:new
            WHERE id=?i:id';

        foreach ($parents as $id => $new) {
            $vars = [
                ':id'  => $id,
                ':new' => $new,
            ];

            if (false === $db->exec($query, $vars)) {
                return false;
            }
        }

        $query = 'SELECT id_old, id, forum_name, friendly_name
            FROM ::forums
            WHERE id_old>0
            ORDER BY id_old';

        $data = $db->query($query)->fetchAll(PDO::FETCH_UNIQUE);

        return false !== $this->c->Cache->set('repl_of_forums', $data);
    }

    /*************************************************************************/
    /* forum_perms                                                           */
    /*************************************************************************/
    public function forum_permsSet(DB $db, array $vars): bool
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

        $vars['group_id'] = $this->replGroups[$vars['group_id']] ?? $vars['group_id'];
        $vars['forum_id'] = $this->replForums[$vars['forum_id']];

        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function forum_permsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* bbcode                                                                */
    /*************************************************************************/
    public function bbcodePre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function bbcodeGet(int &$id): ?array
    {
        return null;
    }

    public function bbcodeSet(DB $db, array $vars): bool
    {
        return true;
    }

    public function bbcodeEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* censoring                                                             */
    /*************************************************************************/
    public function censoringSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function censoringEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* smilies                                                               */
    /*************************************************************************/
    public function smiliesPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function smiliesGet(int &$id): ?array
    {
        return null;
    }

    public function smiliesSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function smiliesEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* topics                                                                */
    /*************************************************************************/
    public function topicsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function topicsEnd(DB $db): bool
    {
        $query = 'UPDATE ::topics
            SET forum_id=(
                SELECT f.id
                FROM ::forums AS f
                WHERE f.id_old=forum_id
            )
            WHERE id_old>0 AND forum_id!=?i:fid';

        if (false === $db->exec($query, [':fid' => FORK_SFID])) {
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

        while ($vars = $stmt->fetch()) {
            if (false === $db->exec($query, $vars)) {
                return false;
            }
        }

        $query = 'SELECT id_old, id, subject
            FROM ::topics
            WHERE id_old>0
            ORDER BY id_old';

        $data = $db->query($query)->fetchAll(PDO::FETCH_UNIQUE);

        return false !== $this->c->Cache->set('repl_of_topics', $data);
    }

    /*************************************************************************/
    /* posts                                                                 */
    /*************************************************************************/
    public function postsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
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
            SET poster=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=poster_id
                ),
                poster
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

        $query = 'UPDATE ::posts
            SET editor=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=editor_id
                ),
                editor
            )
            WHERE id_old>0 AND editor_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'SELECT id_old, id
            FROM ::posts
            WHERE id_old>0
            ORDER BY id_old';

        $data = $db->query($query)->fetchAll(PDO::FETCH_COLUMN);

        return false !== $this->c->Cache->set('repl_of_posts', $data);
    }

    /*************************************************************************/
    /* topics_again                                                          */
    /*************************************************************************/
    public function topics_againPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function topics_againGet(int &$id): ?array
    {
        return null;
    }

    public function topics_againSet(DB $db, array $vars): bool
    {
        return true;
    }

    public function topics_againEnd(DB $db): bool
    {
        $query = 'UPDATE ::topics
            SET first_post_id=COALESCE(
                (
                    SELECT MIN(p.id)
                    FROM ::posts AS p
                    WHERE p.topic_id=::topics.id
                ),
                0
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET last_post_id=COALESCE(
                (
                    SELECT MAX(p.id)
                    FROM ::posts AS p
                    WHERE p.topic_id=::topics.id
                ),
                0
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET poster_id=(
                SELECT p.poster_id
                FROM ::posts AS p
                WHERE p.id=::topics.first_post_id
            )
            WHERE id_old>0 AND first_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET poster=(
                SELECT p.poster
                FROM ::posts AS p
                WHERE p.id=::topics.first_post_id
            )
            WHERE id_old>0 AND first_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET last_poster_id=(
                SELECT p.poster_id
                FROM ::posts AS p
                WHERE p.id=::topics.last_post_id
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET last_poster=(
                SELECT p.poster
                FROM ::posts AS p
                WHERE p.id=::topics.last_post_id
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET solution=(
                SELECT p.id
                FROM ::posts AS p
                WHERE p.id_old=::topics.solution
            )
            WHERE id_old>0 AND solution>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::topics
            SET solution_wa_id=(
                SELECT u.id
                FROM ::users AS u
                WHERE u.id_old=::topics.solution_wa_id
            )
            WHERE id_old>0 AND solution_wa_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* forums_again                                                          */
    /*************************************************************************/
    public function forums_againPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function forums_againGet(int &$id): ?array
    {
        return null;
    }

    public function forums_againSet(DB $db, array $vars): bool
    {
        return true;
    }

    public function forums_againEnd(DB $db): bool
    {
        $query = 'UPDATE ::forums
            SET last_post_id=COALESCE(
                (
                    SELECT MAX(t.last_post_id)
                    FROM ::topics AS t
                    WHERE t.forum_id=::forums.id
                ),
                0
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::forums
            SET last_poster_id=(
                SELECT p.poster_id
                FROM ::posts AS p
                WHERE p.id=::forums.last_post_id
            )
            WHERE id_old>0 AND last_post_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::forums
            SET last_poster=(
                SELECT p.poster
                FROM ::posts AS p
                WHERE p.id=::forums.last_post_id
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
    public function warningsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function warningsEnd(DB $db): bool
    {
        $query = 'UPDATE ::warnings
            SET poster_id=COALESCE(
                (
                    SELECT u.id
                    FROM ::users AS u
                    WHERE u.id_old=poster_id
                ),
                0
            )
            WHERE poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::warnings
            SET poster=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=poster_id
                ),
                poster
            )
            WHERE poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* reports                                                               */
    /*************************************************************************/
    public function reportsPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function reportsGet(int &$id): ?array
    {
        return null;
    }

    public function reportsSet(DB $db, array $vars): bool
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
    public function forum_subscriptionsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function forum_subscriptionsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* topic_subscriptions                                                   */
    /*************************************************************************/
    public function topic_subscriptionsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function topic_subscriptionsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* mark_of_forum                                                         */
    /*************************************************************************/
    public function mark_of_forumPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function mark_of_forumGet(int &$id): ?array
    {
        return null;
    }

    public function mark_of_forumSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function mark_of_forumEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* mark_of_topic                                                         */
    /*************************************************************************/
    public function mark_of_topicPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function mark_of_topicGet(int &$id): ?array
    {
        return null;
    }

    public function mark_of_topicSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function mark_of_topicEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* poll                                                                  */
    /*************************************************************************/
    public function pollSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function pollEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* poll_voted                                                            */
    /*************************************************************************/
    public function poll_votedSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function poll_votedEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* pm_topics                                                             */
    /*************************************************************************/
    public function pm_topicsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
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
            SET poster=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=poster_id
                ),
                poster
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

        $query = 'UPDATE ::pm_topics
            SET target=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=target_id
                ),
                target
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
    public function pm_postsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
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

        $query = 'UPDATE ::pm_posts
            SET poster=COALESCE(
                (
                    SELECT u.username
                    FROM ::users AS u
                    WHERE u.id=poster_id
                ),
                poster
            )
            WHERE id_old>0 AND poster_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* pm_topics_again                                                       */
    /*************************************************************************/
    public function pm_topics_againPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function pm_topics_againGet(int &$id): ?array
    {
        return null;
    }

    public function pm_topics_againSet(DB $db, array $vars): bool
    {
        return true;
    }

    public function pm_topics_againEnd(DB $db): bool
    {
        $query = 'UPDATE ::pm_topics
            SET first_post_id=COALESCE(
                (
                    SELECT MIN(p.id)
                    FROM ::pm_posts AS p
                    WHERE p.topic_id=::pm_topics.id
                ),
                0
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        $query = 'UPDATE ::pm_topics
            SET last_post_id=COALESCE(
                (
                    SELECT MAX(p.id)
                    FROM ::pm_posts AS p
                    WHERE p.topic_id=::pm_topics.id
                ),
                0
            )
            WHERE id_old>0';

        if (false === $db->exec($query)) {
            return false;
        }

        return true;
    }

    /*************************************************************************/
    /* pm_block                                                              */
    /*************************************************************************/
    public function pm_blockPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function pm_blockGet(int &$id): ?array
    {
        return null;
    }

    public function pm_blockSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function pm_blockEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* bans                                                                  */
    /*************************************************************************/
    public function bansSet(DB $db, array $vars): bool
    {
        $key = \mb_strtolower($vars['username'], 'UTF-8');

        if (isset($this->c->rUsernames[$key])) {
            $vars['username'] = $this->c->rUsernames[$key];
        }

        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function bansEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* config                                                                */
    /*************************************************************************/
    public function configSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function configEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* providers                                                             */
    /*************************************************************************/
    public function providersPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function providersGet(int &$id): ?array
    {
        return null;
    }

    public function providersSet(DB $db, array $vars): bool
    {
        return null;
    }

    public function providersEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* providers_users                                                       */
    /*************************************************************************/
    public function providers_usersPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function providers_usersGet(int &$id): ?array
    {
        return null;
    }

    public function providers_usersSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function providers_usersEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* attachments                                                           */
    /*************************************************************************/
    public function attachmentsPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function attachmentsGet(int &$id): ?array
    {
        return null;
    }

    public function attachmentsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function attachmentsEnd(DB $db): bool
    {
        $query = 'UPDATE ::attachments
            SET uid=(
                SELECT u.id
                FROM ::users AS u
                WHERE u.id_old=uid
            )
            WHERE id_old>0';

        return false !== $db->exec($query);
    }

    /*************************************************************************/
    /* attachments_pos                                                       */
    /*************************************************************************/
    public function attachments_posPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function attachments_posGet(int &$id): ?array
    {
        return null;
    }

    public function attachments_posSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function attachments_posEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* attachments_pos_pm                                                    */
    /*************************************************************************/
    public function attachments_pos_pmPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function attachments_pos_pmGet(int &$id): ?array
    {
        return null;
    }

    public function attachments_pos_pmSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function attachments_pos_pmEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* extensions                                                            */
    /*************************************************************************/
    //???? стоит ли их переносить?

    /*************************************************************************/
    /* reactions                                                             */
    /*************************************************************************/
    public function reactionsPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function reactionsGet(int &$id): ?array
    {
        return null;
    }

    public function reactionsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function reactionsEnd(DB $db): bool
    {
        return true;
    }

    /*************************************************************************/
    /* other_again                                                           */
    /*************************************************************************/
    public function other_againPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function other_againGet(int &$id): ?array
    {
        return null;
    }

    public function other_againSet(DB $db, array $vars): bool
    {
        return true;
    }

    public function other_againEnd(DB $db): bool
    {
        $query = 'UPDATE ::users
            SET about_me_id=(
                SELECT p.id
                FROM ::posts AS p
                WHERE p.id_old=::users.about_me_id
            )
            WHERE id_old>0 AND about_me_id>0';

        if (false === $db->exec($query)) {
            return false;
        }

        // start
        $query = 'SELECT conf_value
            FROM ::config
            WHERE conf_name=\'i_about_me_topic_id\'';

        $tid = (int) $db->query($query)->fetchColumn();

        if ($tid > 0) {
            $query = 'SELECT id
                FROM ::topics
                WHERE id_old=?i:tid';

            $newTid = (int) $db->query($query, [':tid' => $tid])->fetchColumn();

            if ($newTid > 0) {
                $query = 'UPDATE ::config
                    SET conf_value=?i:tid
                    WHERE conf_name=\'i_about_me_topic_id\'';

                if (false === $db->exec($query, [':tid' => $newTid])) {
                    return false;
                }
            }

            return true;
        }

        $query = 'SELECT id, subject
            FROM ::topics
            WHERE forum_id=?i:fid
            ORDER BY id';

        $stmt = $db->query($query, [":fid" => FORK_SFID]);

        $inConfig = 'INSERT INTO ::config (conf_name, conf_value)
            VALUES (\'i_about_me_topic_id\', ?i:id)';

        while ($vars = $stmt->fetch()) {
            if ('[system] About me' == $vars['subject']) {
                $this->exec($inConfig, ['id' => $vars['id']]);

                return true;
            }
        }

        $now     = \time();
        $ip      = \filter_var($_SERVER['REMOTE_ADDR'], \FILTER_VALIDATE_IP) ?: '0.0.0.0';
        $topicId = 1;

        $this->c->DB->exec('INSERT INTO ::posts (poster, poster_id, poster_ip, message, posted, topic_id) VALUES(?s, ?i, ?s, ?s, ?i, ?i)', ['ForkBB', 0, $ip, 'Start', $now, $topicId]);

        $postId = (int) $this->c->DB->lastInsertId();

        $this->c->DB->exec('INSERT INTO ::topics (poster, poster_id, subject, posted, first_post_id, last_post, last_post_id, last_poster, last_poster_id, forum_id) VALUES(?s, ?i, ?s, ?i, ?i, ?i, ?i, ?s, ?i, ?i)', ['ForkBB', 0, '[system] About me', $now, $postId, $now, $postId, 'ForkBB', 0, FORK_SFID]);

        $topicId = (int) $this->c->DB->lastInsertId();

        $this->c->DB->exec('UPDATE ::posts SET topic_id=?i WHERE id=?i', [$topicId, $postId]);

        $this->exec($inConfig, ['id' => $topicId]);
        //end

        return true;
    }

    /*************************************************************************/
    /* drafts                                                                */
    /*************************************************************************/
    public function draftsPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function draftsGet(int &$id): ?array
    {
        return null;
    }

    public function draftsSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function draftsEnd(DB $db): bool
    {
        return true;
    }
}
