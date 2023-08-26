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
    protected array $reqTables = [
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

    /**
     * @var string
     */
    protected string $min = '42';

    /**
     * @var string
     */
    protected string $max = '68';

    public function getType(): string
    {
        return 'ForkBB';
    }

    public function test(DB $db): bool|string|array
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
            if (
                \version_compare($rev, $this->min, '<')
                || \version_compare($rev, $this->max, '>')
            ) {
                return [
                    'Current version \'%1$s\' is %2$s, need %3$s to %4$s',
                    $this->getType(),
                    "rev.{$rev}",
                    "rev.{$this->min}",
                    "rev.{$this->max}",
                ];
            }

            return true;
        }
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
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

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::groups
            WHERE g_id>4 AND g_id>=?i:id
            ORDER BY g_id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function groupsGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['g_id'];
        $vars['id_old'] = $id;

        unset($vars['g_id']);

        $vars['g_up_ext']         ??= 'webp,jpg,jpeg,png,gif,avif';
        $vars['g_up_size_kb']     ??= 0;
        $vars['g_up_limit_mb']    ??= 0;
        $vars['g_delete_profile'] ??= 0;

        return $vars;
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
            ':not'   => [FORK_GROUP_UNVERIFIED, FORK_GROUP_GUEST],
        ];
        $query = 'SELECT *
            FROM ::users
            WHERE id>=?i:id AND group_id NOT IN (?ai:not)
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function usersGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id                     = (int) $vars['id'];
        $vars['id_old']         = $id;
        $vars['last_report_id'] = 0;

        unset($vars['id']);

        $vars['u_up_size_mb'] ??= 0;
        $vars['unfollowed_f'] ??= '';

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
    }

    public function forumsSet(DB $db, array $vars): bool
    {
        if ('' !== $vars['moderators']) {
            $mods = \json_decode($vars['moderators'], true);

            if (\is_array($mods)) {
                $vars2 = [
                    ':ids' => \array_keys($mods),
                ];
                $query2 = 'SELECT id, username
                    FROM ::users
                    WHERE id_old>0 AND id_old IN (?ai:ids)';

                $stmt = $db->query($query2, $vars2);

                $mods = [];

                while ($row = $stmt->fetch()) {
                    $mods[$row['id']] = $row['username'];
                }

                $vars['moderators'] = \json_encode($mods);
            } else {
                $vars['moderators'] = '';
            }
        }

        return false !== $db->exec($this->insertQuery, $vars);
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        return $vars;
    }

    /*************************************************************************/
    /* bbcode                                                                */
    /*************************************************************************/
    public function bbcodePre(DB $db, int $id): ?bool
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $vars['id'];

        unset($vars['id']);

        return $vars;
    }

    public function bbcodeSet(DB $db, array $vars): bool
    {
        $exist = (int) $db->query('SELECT 1 FROM ::bbcode WHERE bb_tag=?s:bb_tag', $vars)->fetchColumn();
        $query = 1 === $exist ? $this->updateQuery : $this->insertQuery;

        return false !== $db->exec($query, $vars);
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $vars['id'];

        unset($vars['id']);

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = $vars['id'];

        unset($vars['id']);

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
    }

    /*************************************************************************/
    /* topics_again                                                          */
    /*************************************************************************/

    /*************************************************************************/
    /* forums_again                                                          */
    /*************************************************************************/

    /*************************************************************************/
    /* warnings                                                              */
    /*************************************************************************/
    public function warningsPre(DB $db, int $id): bool
    {
        $this->insertQuery = 'INSERT INTO ::warnings (id, poster, poster_id, posted, message)
            SELECT (
                SELECT id
                FROM ::posts
                WHERE id_old=?i:id
            ), ?s:poster, ?i:poster_id, ?i:posted, ?s:message';


        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::warnings
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;

    }

    public function warningsGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['id'];

        return $vars;
    }

    /*************************************************************************/
    /* reports                                                               */
    /*************************************************************************/

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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['user_id'];

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['user_id'];

        return $vars;
    }

    /*************************************************************************/
    /* mark_of_forum                                                         */
    /*************************************************************************/
    public function mark_of_forumPre(DB $db, int $id): ?bool
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['uid'];

        return $vars;
    }

    /*************************************************************************/
    /* mark_of_topic                                                         */
    /*************************************************************************/
    public function mark_of_topicPre(DB $db, int $id): ?bool
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['uid'];

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['tid'];

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['tid'];

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
    }

    /*************************************************************************/
    /* pm_topics_again                                                       */
    /*************************************************************************/

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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['bl_first_id'];

        return $vars;
    }

    /*************************************************************************/
    /* bans                                                                  */
    /*************************************************************************/
    public function bansPre(DB $db, int $id): bool
    {
        $this->insertQuery = 'INSERT INTO ::bans (username, ip, email, message, expire, ban_creator)
            SELECT ?s:username, ?s:ip, ?s:email, ?s:message, ?i:expire, COALESCE((
                SELECT id
                FROM ::users
                WHERE id_old=?i:ban_creator
            ), 0)';

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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['id'];

        return $vars;
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        if ('i_default_user_group' === $vars['conf_name']) {
            $vars['conf_value'] = FORK_GROUP_NEW_MEMBER;
        }

        return $vars;
    }

    /*************************************************************************/
    /* providers                                                             */
    /*************************************************************************/
    public function providersPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['providers'])) {
            return null;
        }

        $map = $this->c->dbMapArray['providers'];

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::providers ({$sub['fields']}) VALUES ({$sub['values']})";

        unset($map['pr_name']);

        $sub               = $this->subQUpdate($map);
        $this->updateQuery = "UPDATE ::providers SET {$sub['sets']} WHERE pr_name=?s:pr_name AND pr_cl_id='' AND pr_cl_sec=''";

        $query = 'SELECT *
            FROM ::providers';

        $this->stmt = $db->query($query);

        return false !== $this->stmt;
    }

    public function providersGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        return $vars;
    }

    public function providersSet(DB $db, array $vars): bool
    {
        $exist = (int) $db->query('SELECT 1 FROM ::providers WHERE pr_name=?s:pr_name', $vars)->fetchColumn();
        $query = 1 === $exist ? $this->updateQuery : $this->insertQuery;

        return false !== $db->exec($query, $vars);
    }

    /*************************************************************************/
    /* providers_users                                                       */
    /*************************************************************************/
    public function providers_usersPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['providers_users'])) {
            return null;
        }

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT uid
            FROM ::providers_users
            WHERE uid>=?i:id
            ORDER BY uid
            LIMIT ?i:limit';

        $ids = $db->query($query, $vars)->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            $max = $id;
        } else {
            $max = \array_pop($ids);
        }

        $this->insertQuery = 'INSERT INTO ::providers_users (uid, pr_name, pu_uid, pu_email, pu_email_normal, pu_email_verified)
            SELECT (
                SELECT id
                FROM ::users
                WHERE id_old=?i:uid
            ), ?s:pr_name, ?s:pu_uid, ?s:pu_email, ?s:pu_email_normal, ?i:pu_email_verified';

        $vars = [
            ':id'  => $id,
            ':max' => $max,
        ];
        $query = 'SELECT *
            FROM ::providers_users
            WHERE uid>=?i:id AND uid<=?i:max
            ORDER BY uid';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function providers_usersGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['uid'];

        return $vars;
    }

    /*************************************************************************/
    /* attachments                                                           */
    /*************************************************************************/
    public function attachmentsPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['attachments'])) {
            return null;
        }

        $map = $this->c->dbMapArray['attachments'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::attachments ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::attachments
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    public function attachmentsGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id             = (int) $vars['id'];
        $vars['id_old'] = $id;

        unset($vars['id']);

        return $vars;
    }

    /*************************************************************************/
    /* attachments_pos                                                       */
    /*************************************************************************/

    /*************************************************************************/
    /* attachments_pos_pm                                                    */
    /*************************************************************************/
}
