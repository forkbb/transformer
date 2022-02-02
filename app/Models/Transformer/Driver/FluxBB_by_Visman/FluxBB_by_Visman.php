<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Transformer\Driver\FluxBB_by_Visman;

use ForkBB\Core\DB;
use ForkBB\Models\Transformer\Driver\AbstractDriver;
use PDO;
use PDOException;
use InvalidArgumentException;
use RuntimeException;

class FluxBB_by_Visman extends AbstractDriver
{
    /**
     * @var array
     */
    protected $reqTables = [
        'bans',
        'categories',
        'censoring',
        'config',
        'forums',
        'forum_perms',
        'forum_subscriptions',
        'groups',
        'online',
        'pms_new_block',
        'pms_new_posts',
        'pms_new_topics',
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
    protected $min = '78';

    /**
     * @var string
     */
    protected $max = '83';

    public function getType(): string
    {
        return 'FluxBB_by_Visman';
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
            WHERE conf_name=\'o_cur_ver_revision\'';
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

        $id = (int) $vars['id'];

        return [
            'id_old'        => $id,
            'cat_name'      => $vars['cat_name'],
            'disp_position' => $vars['disp_position'],
        ];
    }

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

        $id = (int) $vars['g_id'];

        return [
            'id_old'                 => $id,
            'g_title'                => (string) $vars['g_title'],
            'g_user_title'           => (string) $vars['g_user_title'],
            'g_promote_min_posts'    => (int) $vars['g_promote_min_posts'],
            'g_promote_next_group'   => (int) $vars['g_promote_next_group'],
            'g_moderator'            => (int) $vars['g_moderator'],
            'g_mod_edit_users'       => (int) $vars['g_mod_edit_users'],
            'g_mod_rename_users'     => (int) $vars['g_mod_rename_users'],
            'g_mod_change_passwords' => (int) $vars['g_mod_change_passwords'],
            'g_mod_ban_users'        => (int) $vars['g_mod_ban_users'],
            'g_mod_promote_users'    => (int) $vars['g_mod_promote_users'],
            'g_read_board'           => (int) $vars['g_read_board'],
            'g_view_users'           => (int) $vars['g_view_users'],
            'g_post_replies'         => (int) $vars['g_post_replies'],
            'g_post_topics'          => (int) $vars['g_post_topics'],
            'g_edit_posts'           => (int) $vars['g_edit_posts'],
            'g_delete_posts'         => (int) $vars['g_delete_posts'],
            'g_delete_topics'        => (int) $vars['g_delete_topics'],
            'g_post_links'           => (int) $vars['g_post_links'],
            'g_set_title'            => (int) $vars['g_set_title'],
            'g_search'               => (int) $vars['g_search'],
            'g_search_users'         => (int) $vars['g_search_users'],
            'g_send_email'           => (int) $vars['g_send_email'],
            'g_post_flood'           => (int) $vars['g_post_flood'],
            'g_search_flood'         => (int) $vars['g_search_flood'],
            'g_email_flood'          => (int) $vars['g_email_flood'],
            'g_report_flood'         => (int) $vars['g_report_flood'],
            'g_deledit_interval'     => (int) $vars['g_deledit_interval'],
            'g_pm'                   => (int) $vars['g_pm'],
            'g_pm_limit'             => (int) $vars['g_pm_limit'],
            'g_sig_length'           => 400,
            'g_sig_lines'            => 4,
        ];
    }

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

    protected function avatarName(int $id): string
    {
        $path = $this->c->DIR_PUBLIC . $this->c->config->o_avatars_dir . '/';

        foreach (['jpg', 'gif', 'png'] as $ext) {
            $file = "{$id}.{$ext}";

            if (\is_file($path . $file)) {
                return $file;
            }
        }

        return '';
    }

    public function usersGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id  = (int) $vars['id'];
        $now = \time();

        return [
            'id_old'           => $id,
            'group_id'         => (int) $vars['group_id'],
            'username'         => $vars['username'],
            'username_normal'  => $this->c->users->normUsername($vars['username']),
            'password'         => $vars['password'],
            'email'            => $vars['email'],
            'email_normal'     => $this->c->NormEmail->normalize($vars['email']),
            'email_confirmed'  => 0,
            'title'            => (string) $vars['title'],
            'avatar'           => $this->avatarName($id),
            'realname'         => (string) $vars['realname'],
            'url'              => (string) $vars['url'],
            'jabber'           => (string) $vars['jabber'],
            'icq'              => (string) $vars['icq'],
            'msn'              => '',
            'aim'              => '',
            'yahoo'            => '',
            'location'         => (string) $vars['location'],
            'signature'        => (string) $vars['signature'],
            'disp_topics'      => (int) $vars['disp_topics'],
            'disp_posts'       => (int) $vars['disp_posts'],
            'email_setting'    => (int) $vars['email_setting'],
            'notify_with_post' => (int) $vars['notify_with_post'],
            'auto_notify'      => (int) $vars['auto_notify'],
            'show_smilies'     => (int) $vars['show_smilies'],
            'show_img'         => (int) $vars['show_img'],
            'show_img_sig'     => (int) $vars['show_img_sig'],
            'show_avatars'     => (int) $vars['show_avatars'],
            'show_sig'         => (int) $vars['show_sig'],
            'timezone'         => (string) $vars['timezone'],
            'dst'              => (int) $vars['dst'],
            'time_format'      => (int) $vars['time_format'],
            'date_format'      => (int) $vars['date_format'],
            'language'         => 'Russian' == $vars['language'] ? 'ru' : 'en',
            'style'            => 'ForkBB',
            'num_posts'        => (int) $vars['num_posts'],
            'num_topics'       => 0,
            'last_post'        => (int) $vars['last_post'],
            'last_search'      => (int) $vars['last_search'],
            'last_email_sent'  => (int) $vars['last_email_sent'],
            'last_report_sent' => (int) $vars['last_report_sent'],
            'registered'       => (int) $vars['registered'],
            'registration_ip'  => (string) $vars['registration_ip'],
            'last_visit'       => (int) $vars['last_visit'],
            'admin_note'       => (string) $vars['admin_note'],
            'activate_string'  => '',
            'u_pm'             => (int) $vars['messages_enable'],
            'u_pm_notify'      => (int) $vars['messages_email'],
            'u_pm_flash'       => (int) $vars['messages_flag'], // ????
            'u_pm_num_new'     => (int) $vars['messages_new'],
            'u_pm_num_all'     => (int) $vars['messages_all'],
            'u_pm_last_post'   => (int) $vars['pmsn_last_post'],
            'warning_flag'     => (int) $vars['warning_flag'],
            'warning_all'      => (int) $vars['warning_all'],
            'gender'           => (int) $vars['gender'],
            'u_mark_all_read'  => (int) $vars['last_visit'] ?: $now - $now % 86400,
            'last_report_id'   => 0,
            'ip_check_type'    => 0,
            'login_ip_cache'   => '',
        ];
    }

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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id  = (int) $vars['id'];

        return [
            'id_old'          => $id,
            'forum_name'      => (string) $vars['forum_name'],
            'forum_desc'      => (string) $vars['forum_desc'],
            'redirect_url'    => (string) $vars['redirect_url'],
            'moderators'      => (string) $vars['moderators'],
            'num_topics'      => (int) $vars['num_topics'],
            'num_posts'       => (int) $vars['num_posts'],
            'last_post'       => (int) $vars['last_post'],
            'last_post_id'    => (int) $vars['last_post_id'],
            'last_poster'     => (string) $vars['last_poster'],
            'last_poster_id'  => 0,
            'last_topic'      => (string) $vars['last_topic'],
            'sort_by'         => (int) $vars['sort_by'],
            'disp_position'   => (int) $vars['disp_position'],
            'cat_id'          => (int) $vars['cat_id'],
            'no_sum_mess'     => (int) $vars['no_sum_mess'],
            'parent_forum_id' => (int) $vars['parent_forum_id'],
        ];
    }

    public function forumsSet(DB $db, array $vars): bool
    {
        if ('' !== $vars['moderators']) {
            $mods = \unserialize($vars['moderators']);

            if (\is_array($mods)) {
                $vars2 = [
                    ':ids' => \array_values($mods),
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

        $query = 'SELECT f.parent_forum_id, o.id
            FROM ::forums AS f
            LEFT JOIN ::forums AS o ON o.id_old=f.parent_forum_id
            WHERE f.id_old>0 AND f.parent_forum_id>0';

        $parents = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);;

        $query = 'UPDATE ::forums
            SET parent_forum_id=?i:new
            WHERE id_old>0 AND parent_forum_id=?i:old';

        foreach ($parents as $old => $new) {
            $vars = [
                ':old' => $old,
                ':new' => $new,
            ];

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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            $id = -1;

            return null;
        }

        return [
            'group_id'     => (int) $vars['group_id'],
            'forum_id'     => (int) $vars['forum_id'],
            'read_forum'   => (int) $vars['read_forum'],
            'post_replies' => (int) $vars['post_replies'],
            'post_topics'  => (int) $vars['post_topics'],
        ];
    }

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
    public function bbcodePre(DB $db, int $id): bool
    {
        return true;
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

        return [
            'search_for'   => (string) $vars['search_for'],
            'replace_with' => (string) $vars['replace_with'],
        ];
    }

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

        return [
            'sm_image'    => (string) $vars['image'],
            'sm_code'     => (string) $vars['text'],
            'sm_position' => (int) $vars['disp_position'],
        ];
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

        $id = (int) $vars['id'];

        return [
            'id_old'         => $id,
            'poster'         => (string) $vars['poster'],
            'poster_id'      => 0,
            'subject'        => (string) $vars['subject'],
            'posted'         => (int) $vars['posted'],
            'first_post_id'  => (int) $vars['first_post_id'],
            'last_post'      => (int) $vars['last_post'],
            'last_post_id'   => (int) $vars['last_post_id'],
            'last_poster'    => (string) $vars['last_poster'],
            'last_poster_id' => 0,
            'num_views'      => (int) $vars['num_views'],
            'num_replies'    => (int) $vars['num_replies'],
            'closed'         => (int) $vars['closed'],
            'sticky'         => (int) $vars['sticky'],
            'stick_fp'       => (int) $vars['stick_fp'],
            'moved_to'       => (int) $vars['moved_to'],
            'forum_id'       => (int) $vars['forum_id'],
            'poll_type'      => (int) $vars['poll_type'],
            'poll_time'      => (int) $vars['poll_time'],
            'poll_term'      => (int) $vars['poll_term'],
        ];
    }

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
            WHERE id_old>0';

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

        while ($vars = $stmt->fetch()) {
            if (false === $db->exec($query, $vars)) {
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['id'];

        return [
            'id_old'       => $id,
            'poster'       => (string) $vars['poster'],
            'poster_id'    => (int) $vars['poster_id'],
            'poster_ip'    => (string) $vars['poster_ip'],
            'poster_email' => (string) $vars['poster_email'],
            'message'      => (string) $vars['message'],
            'hide_smilies' => (int) $vars['hide_smilies'],
            'edit_post'    => (int) $vars['edit_post'],
            'posted'       => (int) $vars['posted'],
            'edited'       => (int) $vars['edited'],
            'editor'       => (string) $vars['edited_by'],
            'editor_id'    => 0,
            'user_agent'   => (string) $vars['user_agent'],
            'topic_id'     => (int) $vars['topic_id'],
        ];
    }

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

        return true;
    }

    /*************************************************************************/
    /* topics_again                                                          */
    /*************************************************************************/
    public function topics_againPre(DB $db, int $id): bool
    {
        return true;
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

        return true;
    }

    /*************************************************************************/
    /* forums_again                                                          */
    /*************************************************************************/
    public function forums_againPre(DB $db, int $id): bool
    {
        return true;
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

        return [
            'id'        => $id,
            'poster'    => (string) $vars['poster'],
            'poster_id' => (int) $vars['poster_id'],
            'posted'    => (int) $vars['posted'],
            'message'   => (string) $vars['message'],
        ];
    }

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
    public function reportsPre(DB $db, int $id): bool
    {
        return true;
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

        return [
            'user_id'  => (int) $vars['user_id'],
            'forum_id' => (int) $vars['forum_id'],
        ];
    }

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

        return [
            'user_id'  => (int) $vars['user_id'],
            'topic_id' => (int) $vars['topic_id'],
        ];
    }

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
    public function mark_of_forumPre(DB $db, int $id): bool
    {
        return true;
    }

    public function mark_of_forumGet(int &$id): ?array
    {
        return null;
    }

    public function mark_of_forumSet(DB $db, array $vars): bool
    {
        return true;
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
        return true;
    }

    public function mark_of_topicGet(int &$id): ?array
    {
        return null;
    }

    public function mark_of_topicSet(DB $db, array $vars): bool
    {
        return true;
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
        $query = 'SELECT p.*, t.poll_kol
            FROM ::poll AS p
            LEFT JOIN ::topics AS t ON t.id=p.tid
            WHERE p.tid>=?i:id AND p.tid<=?i:max
            ORDER BY p.tid';

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

        if (empty($vars['field'])) {
            $vars['choice'] = "{$vars['votes']}|{$vars['choice']}";
            $vars['votes']  = $vars['poll_kol'];
        }

        return [
            'tid'         => (int) $vars['tid'],
            'question_id' => (int) $vars['question'],
            'field_id'    => (int) $vars['field'],
            'qna_text'    => (string) $vars['choice'],
            'votes'       => (int) $vars['votes'],
        ];
    }

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

        return [
            'tid' => (int) $vars['tid'],
            'uid' => (int) $vars['uid'],
            'rez' => '', // ????
        ];
    }

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
            FROM ::pms_new_topics
            WHERE id>=?i:id
            ORDER BY id
            LIMIT ?i:limit';

        $this->stmt = $db->query($query, $vars);

        return false !== $this->stmt;
    }

    protected function pmStatus(int $status, int $visit): int
    {
        switch ($status) {
            case 0:
            case 1:
                $status = 2;

                break;
            case 2:
                $status = empty($visit) ? 1 : 0;
        }

        return $status;
    }

    public function pm_topicsGet(int &$id): ?array
    {
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['id'];

        return [
            'id_old'        => $id,
            'subject'       => (string) $vars['topic'],
            'poster'        => (string) $vars['starter'],
            'poster_id'     => (int) $vars['starter_id'],
            'poster_status' => $this->pmStatus((int) $vars['topic_st'], (int) $vars['see_st']),
            'poster_visit'  => (int) $vars['see_st'],
            'target'        => (string) $vars['to_user'],
            'target_id'     => (int) $vars['to_id'],
            'target_status' => $this->pmStatus((int) $vars['topic_to'], (int) $vars['see_to']),
            'target_visit'  => (int) $vars['see_to'],
            'num_replies'   => (int) $vars['replies'],
            'first_post_id' => 0,
            'last_post'     => (int) $vars['last_posted'],
            'last_post_id'  => 0,
            'last_number'   => (int) $vars['last_poster'],
        ];
    }

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
            FROM ::pms_new_posts
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

        $id = (int) $vars['id'];

        return [
            'id_old'       => $id,
            'poster'       => (string) $vars['poster'],
            'poster_id'    => (int) $vars['poster_id'],
            'poster_ip'    => (string) $vars['poster_ip'],
            'message'      => (string) $vars['message'],
            'hide_smilies' => (int) $vars['hide_smilies'],
            'posted'       => (int) $vars['posted'],
            'edited'       => (int) $vars['edited'],
            'topic_id'     => (int) $vars['topic_id'],
        ];
    }

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
    public function pm_topics_againPre(DB $db, int $id): bool
    {
        return true;
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
    public function pm_blockPre(DB $db, int $id): bool
    {
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT bl_id
            FROM ::pms_new_block
            WHERE bl_id>=?i:id
            ORDER BY bl_id
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
            FROM ::pms_new_block
            WHERE bl_id>=?i:id AND bl_id<=?i:max
            ORDER BY bl_id';

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

        $id = (int) $vars['bl_id'];

        return [
            'bl_first_id'  => (int) $vars['bl_id'],
            'bl_second_id' => (int) $vars['bl_user_id'],
        ];
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
        $vars = $this->stmt->fetch();

        if (false === $vars) {
            $this->stmt->closeCursor();
            $this->stmt = null;

            return null;
        }

        $id = (int) $vars['id'];

        return [
            'username'    => (string) $vars['username'],
            'ip'          => (string) $vars['ip'],
            'email'       => (string) $vars['email'],
            'message'     => (string) $vars['message'],
            'expire'      => (int) $vars['expire'],
            'ban_creator' => (int) $vars['ban_creator'],
        ];
    }

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
    /**
     * @var array
     */
    protected $newConfig;

    public function configPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['config'];

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::config ({$sub['fields']}) VALUES ({$sub['values']})";

        $query = 'SELECT conf_name, conf_value
            FROM ::config';

        $old = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->newConfig = [
            ['i_fork_revision'         , $this->c->FORK_REVISION],
            ['o_board_title'           , (string) $old['o_board_title']],
            ['o_board_desc'            , (string) $old['o_board_desc']],
            ['o_default_timezone'      , (string) $old['o_default_timezone']],
            ['i_timeout_visit'         , (int) $old['o_timeout_visit']],
            ['i_timeout_online'        , (int) $old['o_timeout_online']],
            ['i_redirect_delay'        , (int) $old['o_redirect_delay']],
            ['b_show_user_info'        , '1' == $old['o_show_user_info'] ? 1 : 0],
            ['b_show_post_count'       , '1' == $old['o_show_post_count'] ? 1 : 0],
            ['b_smilies'               , '1' == $old['o_smilies'] ? 1 : 0],
            ['b_smilies_sig'           , '1' == $old['o_smilies_sig'] ? 1 : 0],
            ['b_make_links'            , '1' == $old['o_make_links'] ? 1 : 0],
            ['o_default_lang'          , 'Russian' == $old['o_default_lang'] ? 'ru' : 'en'],
            ['o_default_style'         , 'ForkBB'],
            ['i_default_user_group'    , FORK_GROUP_NEW_MEMBER],
            ['i_topic_review'          , (int) $old['o_topic_review']],
            ['i_disp_topics_default'   , (int) $old['o_disp_topics_default']],
            ['i_disp_posts_default'    , (int) $old['o_disp_posts_default']],
            ['i_disp_users'            , 50],
            ['b_quickpost'             , '1' == $old['o_quickpost'] ? 1 : 0],
            ['b_users_online'          , '1' == $old['o_users_online'] ? 1 : 0],
            ['b_censoring'             , '1' == $old['o_censoring'] ? 1 : 0],
            ['b_show_dot'              , '1' == $old['o_show_dot'] ? 1 : 0],
            ['b_topic_views'           , '1' == $old['o_topic_views'] ? 1 : 0],
            ['o_additional_navlinks'   , ''],
            ['i_report_method'         , (int) $old['o_report_method']],
            ['b_regs_report'           , '1' == $old['o_regs_report'] ? 1 : 0],
            ['i_default_email_setting' , (int) $old['o_default_email_setting']],
            ['o_mailing_list'          , (string) $old['o_mailing_list']],
            ['b_avatars'               , '1' == $old['o_avatars'] ? 1 : 0],
            ['o_avatars_dir'           , '/img/avatars'],
            ['i_avatars_width'         , (int) $old['o_avatars_width']],
            ['i_avatars_height'        , (int) $old['o_avatars_height']],
            ['i_avatars_size'          , (int) $old['o_avatars_size']],
            ['o_admin_email'           , (string) $old['o_admin_email']],
            ['o_webmaster_email'       , (string) $old['o_webmaster_email']],
            ['b_forum_subscriptions'   , '1' == $old['o_forum_subscriptions'] ? 1 : 0],
            ['b_topic_subscriptions'   , '1' == $old['o_topic_subscriptions'] ? 1 : 0],
            ['i_email_max_recipients'  , 1],
            ['o_smtp_host'             , (string) $old['o_smtp_host']],
            ['o_smtp_user'             , (string) $old['o_smtp_user']],
            ['o_smtp_pass'             , (string) $old['o_smtp_pass']],
            ['b_smtp_ssl'              , '1' == $old['o_smtp_ssl'] ? 1 : 0],
            ['b_regs_allow'            , '1' == $old['o_regs_allow'] ? 1 : 0],
            ['b_regs_verify'           , '1' == $old['o_regs_verify'] ? 1 : 0],
            ['b_announcement'          , '1' == $old['o_announcement'] ? 1 : 0],
            ['o_announcement_message'  , (string) $old['o_announcement_message']],
            ['b_rules'                 , '1' == $old['o_rules'] ? 1 : 0],
            ['o_rules_message'         , (string) $old['o_rules_message']],
            ['b_maintenance'           , '1' == $old['o_maintenance'] ? 1 : 0],
            ['o_maintenance_message'   , (string) $old['o_maintenance_message']],
            ['b_default_dst'           , '1' == $old['o_default_dst'] ? 1 : 0],
            ['i_feed_type'             , (int) $old['o_feed_type']],
            ['i_feed_ttl'              , (int) $old['o_feed_ttl']],
            ['b_message_bbcode'        , '1' == $old['p_message_bbcode'] ? 1 : 0],
            ['b_message_all_caps'      , '1' == $old['p_message_all_caps'] ? 1 : 0],
            ['b_subject_all_caps'      , '1' == $old['p_subject_all_caps'] ? 1 : 0],
            ['b_sig_all_caps'          , '1' == $old['p_sig_all_caps'] ? 1 : 0],
            ['b_sig_bbcode'            , '1' == $old['p_sig_bbcode'] ? 1 : 0],
            ['b_force_guest_email'     , '1' == $old['p_force_guest_email'] ? 1 : 0],
            ['b_pm'                    , '1' == $old['o_pms_enabled'] ? 1 : 0],
            ['b_poll_enabled'          , '1' == $old['o_poll_enabled'] ? 1 : 0],
            ['i_poll_max_questions'    , (int) $old['o_poll_max_ques']],
            ['i_poll_max_fields'       , (int) $old['o_poll_max_field']],
            ['i_poll_time'             , (int) $old['o_poll_time']],
            ['i_poll_term'             , (int) $old['o_poll_term']],
            ['b_poll_guest'            , '1' == $old['o_poll_guest'] ? 1 : 0],
            ['a_max_users'             , \json_encode(
                [
                    'number' => (int) $old['st_max_users'],
                    'time'   => (int) $old['st_max_users_time'],
                ], self::JSON_OPTIONS
            )],
            ['a_bb_white_mes'          , \json_encode([], self::JSON_OPTIONS)],
            ['a_bb_white_sig'          , \json_encode(['b', 'i', 'u', 'color', 'colour', 'email', 'url'], self::JSON_OPTIONS)],
            ['a_bb_black_mes'          , \json_encode([], self::JSON_OPTIONS)],
            ['a_bb_black_sig'          , \json_encode([], self::JSON_OPTIONS)],
            ['a_guest_set'             , \json_encode(
                [
                    'show_smilies' => 1,
                    'show_sig'     => 1,
                    'show_avatars' => 1,
                    'show_img'     => 1,
                    'show_img_sig' => 1,
                ], self::JSON_OPTIONS
            )],
            ['s_РЕГИСТР'               , 'Ok'],
        ];

        return true;
    }

    public function configGet(int &$id): ?array
    {
        if (! \is_array($result = \array_pop($this->newConfig))) {
            $id = -1;

            return null;
        }

        return [
            ':conf_name'  => $result[0],
            ':conf_value' => $result[1],
        ];
    }

    public function configSet(DB $db, array $vars): bool
    {
        return false !== $db->exec($this->insertQuery, $vars);
    }

    public function configEnd(DB $db): bool
    {
        return true;
    }
}
