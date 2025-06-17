<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Transformer\Driver\PunBB;

use ForkBB\Core\DB;
use ForkBB\Models\Transformer\Driver\AbstractDriver;
use PDO;

class PunBB extends AbstractDriver
{
    protected array $reqTables = [
        'bans',
        'categories',
        'censoring',
        'config',
        'extensions',
        'extension_hooks',
        'forums',
        'forum_perms',
        'forum_subscriptions',
        'groups',
        'online',
        'posts',
        'ranks',
        'reports',
        'search_cache',
        'search_matches',
        'search_words',
        'subscriptions',
        'topics',
        'users',
    ];
    protected string $min = '1.4.4';
    protected string $max = '1.4.6';

    public function getType(): string
    {
        return 'PunBB';
    }

    public function test(DB $db, bool $receiver = false): bool|string|array
    {
        if (! $this->reqTablesTest($db)) {
            if (! empty($this->error)) {
                return $this->error;
            }

            return false;
        }

        $query = 'SELECT conf_name, conf_value
            FROM ::config';

        $config = $db->query($query)->fetchAll(PDO::FETCH_KEY_PAIR);

        if (
            isset($config['o_cur_ver_revision'])
            || isset($config['o_searchindex_revision'])
            || empty($config['o_database_revision'])
            || empty($config['o_cur_version'])
        ) {
            return false;
        } elseif (
            \version_compare($config['o_cur_version'], $this->min, '<')
            || \version_compare($config['o_cur_version'], $this->max, '>')
        ) {
            return [
                'Current version \'%1$s\' is %2$s, need %3$s to %4$s',
                $this->getType(),
                $config['o_cur_version'],
                $this->min,
                $this->max,
            ];
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

    /*************************************************************************/
    /* groups                                                                */
    /*************************************************************************/
    public function groupsPre(DB $db, int $id): bool
    {
        $map = $this->c->dbMapArray['groups'];

        unset($map['g_id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::groups ({$sub['fields']}) VALUES ({$sub['values']})";

        $replGroups = [
            FORK_GROUP_GUEST => 2,
        ];

        $query = 'SELECT g_id
            FROM ::groups
            WHERE g_id>2 AND g_moderator=1
            ORDER BY g_id
            LIMIT 1';

        $gid = (int) $db->query($query)->fetchColumn();

        if ($gid) {
            $replGroups[FORK_GROUP_MOD] = $gid;
        }

        $query = 'SELECT g_id
            FROM ::groups
            WHERE g_id>2 AND g_moderator=0
            ORDER BY g_id
            LIMIT 1';

        $gid = (int) $db->query($query)->fetchColumn();

        if ($gid) {
            $replGroups[FORK_GROUP_MEMBER] = $gid;
        }

        $this->c->replGroups = $replGroups;

        // Admin - 1, Guest - 2, Members - 3 (???), Mod - 4 (???)
        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
            ':not'   => $replGroups,
        ];
        $query = 'SELECT *
            FROM ::groups
            WHERE g_id>2 AND g_id NOT IN (?a:not) AND g_id>=?i:id
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
            'g_promote_min_posts'    => 0,
            'g_promote_next_group'   => 0,
            'g_moderator'            => (int) $vars['g_moderator'],
            'g_mod_edit_users'       => (int) $vars['g_mod_edit_users'],
            'g_mod_rename_users'     => (int) $vars['g_mod_rename_users'],
            'g_mod_change_passwords' => (int) $vars['g_mod_change_passwords'],
            'g_mod_ban_users'        => (int) $vars['g_mod_ban_users'],
            'g_mod_promote_users'    => 0,
            'g_read_board'           => (int) $vars['g_read_board'],
            'g_view_users'           => (int) $vars['g_view_users'],
            'g_post_replies'         => (int) $vars['g_post_replies'],
            'g_post_topics'          => (int) $vars['g_post_topics'],
            'g_edit_posts'           => (int) $vars['g_edit_posts'],
            'g_delete_posts'         => (int) $vars['g_delete_posts'],
            'g_delete_topics'        => (int) $vars['g_delete_topics'],
            'g_post_links'           => 1,
            'g_set_title'            => (int) $vars['g_set_title'],
            'g_search'               => (int) $vars['g_search'],
            'g_search_users'         => (int) $vars['g_search_users'],
            'g_send_email'           => (int) $vars['g_send_email'],
            'g_post_flood'           => (int) $vars['g_post_flood'],
            'g_search_flood'         => (int) $vars['g_search_flood'],
            'g_email_flood'          => (int) $vars['g_email_flood'],
            'g_report_flood'         => 60,
            'g_deledit_interval'     => 0,
            'g_pm'                   => (int) ($vars['g_pm'] ?? 1),
            'g_pm_limit'             => (int) ($vars['g_pm_limit'] ?? 100),
            'g_sig_length'           => 400,
            'g_sig_lines'            => 4,
            'g_up_ext'               => 'webp,jpg,jpeg,png,gif,avif',
            'g_up_size_kb'           => 0,
            'g_up_limit_mb'          => 0,
            'g_delete_profile'       => 0,
        ];
    }

    public function groupsEnd(DB $db): bool
    {
        $query = 'UPDATE ::groups
            SET id_old=?i:old
            WHERE g_id=?i:id';

        foreach ($this->c->replGroups as $id => $old) {
            $vars = [
                ':id'  => $id,
                ':old' => $old,
            ];

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
            ':not'   => [0, 2], // UNVERIFIED, GUEST
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
        $l   = match ($vars['language']) {
            'Russian'             => 'ru',
            'French'              => 'fr',
            'Spanish'             => 'es',
            'Simplified_Chinese'  => 'zh',
            'Traditional_Chinese' => 'zh',
            default               => 'en',
        };

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
            'msn'              => (string) $vars['msn'],
            'aim'              => (string) $vars['aim'],
            'yahoo'            => (string) $vars['yahoo'],
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
            'time_format'      => (int) $vars['time_format'],
            'date_format'      => (int) $vars['date_format'],
            'language'         => $l,
            'locale'           => $l,
            'style'            => 'ForkBB',
            'num_posts'        => (int) $vars['num_posts'],
            'num_topics'       => 0,
            'last_post'        => (int) $vars['last_post'],
            'last_search'      => (int) $vars['last_search'],
            'last_email_sent'  => (int) $vars['last_email_sent'],
            'last_report_sent' => 0,
            'registered'       => (int) $vars['registered'],
            'registration_ip'  => (string) $vars['registration_ip'],
            'last_visit'       => (int) $vars['last_visit'],
            'admin_note'       => (string) $vars['admin_note'],
            'activate_string'  => '',
            'u_pm'             => (int) ($vars['messages_enable'] ?? 1),
            'u_pm_notify'      => (int) ($vars['messages_email'] ?? 0),
            'u_pm_flash'       => (int) ($vars['messages_flag'] ?? 0), // ????
            'u_pm_num_new'     => (int) ($vars['messages_new'] ?? 0),
            'u_pm_num_all'     => (int) ($vars['messages_all'] ?? 0),
            'u_pm_last_post'   => (int) ($vars['pmsn_last_post'] ?? 0),
            'warning_flag'     => 0,
            'warning_all'      => 0,
            'gender'           => 0,
            'u_mark_all_read'  => (int) $vars['last_visit'] ?: $now - $now % 86400,
            'last_report_id'   => 0,
            'ip_check_type'    => 0,
            'login_ip_cache'   => '',
            'u_up_size_mb'     => 0,
            'unfollowed_f'     => '',
            'show_reaction'    => 1,
            'page_scroll'      => 0,
            'about_me_id'      => 0,
        ];
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
            'friendly_name'   => '',
            'forum_desc'      => (string) $vars['forum_desc'],
            'redirect_url'    => (string) $vars['redirect_url'],
            'moderators'      => (string) $vars['moderators'],
            'num_topics'      => (int) $vars['num_topics'],
            'num_posts'       => (int) $vars['num_posts'],
            'last_post'       => (int) $vars['last_post'],
            'last_post_id'    => (int) $vars['last_post_id'],
            'last_poster'     => (string) $vars['last_poster'],
            'last_poster_id'  => 0,
            'last_topic'      => '???', // ????
            'sort_by'         => (int) $vars['sort_by'],
            'disp_position'   => (int) $vars['disp_position'],
            'cat_id'          => (int) $vars['cat_id'],
            'no_sum_mess'     => 0,
            'parent_forum_id' => 0,
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

                $vars['moderators'] = \json_encode($mods, FORK_JSON_ENCODE);
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

        return [
            'group_id'     => (int) $vars['group_id'],
            'forum_id'     => (int) $vars['forum_id'],
            'read_forum'   => (int) $vars['read_forum'],
            'post_replies' => (int) $vars['post_replies'],
            'post_topics'  => (int) $vars['post_topics'],
        ];
    }

    /*************************************************************************/
    /* bbcode                                                                */
    /*************************************************************************/

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

    /*************************************************************************/
    /* smilies                                                               */
    /*************************************************************************/

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
            'stick_fp'       => (int) ($vars['stick_fp'] ?? 0),
            'moved_to'       => (int) $vars['moved_to'],
            'forum_id'       => (int) $vars['forum_id'],
            'poll_type'      => (int) ($vars['poll_type'] ?? 0),
            'poll_time'      => (int) ($vars['poll_time'] ?? 0),
            'poll_term'      => (int) ($vars['poll_term'] ?? 0),
        ];
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
            'edit_post'    => 0,
            'posted'       => (int) $vars['posted'],
            'edited'       => (int) $vars['edited'],
            'editor'       => (string) $vars['edited_by'],
            'editor_id'    => 0,
            'user_agent'   => (string) ($vars['user_agent'] ?? ''),
            'topic_id'     => (int) $vars['topic_id'],
        ];
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
    public function warningsPre(DB $db, int $id): ?bool
    {
        return null;
    }

    public function warningsGet(int &$id): ?array
    {
        return null;
    }

    public function warningsSet(DB $db, array $vars): bool
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
            FROM ::subscriptions
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
            FROM ::subscriptions
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

    /*************************************************************************/
    /* mark_of_forum                                                         */
    /*************************************************************************/

    /*************************************************************************/
    /* mark_of_topic                                                         */
    /*************************************************************************/

    /*************************************************************************/
    /* poll                                                                  */
    /*************************************************************************/
    public function pollPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['poll'])) {
            return null;
        }

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

    /*************************************************************************/
    /* poll_voted                                                            */
    /*************************************************************************/
    public function poll_votedPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['poll_voted'])) {
            return null;
        }

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

    /*************************************************************************/
    /* pm_topics                                                             */
    /*************************************************************************/
    public function pm_topicsPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['pun_pm_messages'])) {
            return null;
        }

        $map = $this->c->dbMapArray['pm_topics'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::pm_topics ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::pun_pm_messages
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

        $id = (int) $vars['id'];

        return [
            'id_old'        => $id,
            'subject'       => (string) $vars['subject'],
            'poster'        => '???',
            'poster_id'     => (int) $vars['sender_id'],
            'poster_status' => ! empty((int) $vars['deleted_by_sender'])
                ? 0
                : (
                    'draft' === $vars['status']
                    ? 3
                    : 2
                ),
            'poster_visit'  => (int) $vars['lastedited_at'],
            'target'        => '???',
            'target_id'     => (int) $vars['receiver_id'],
            'target_status' => ! empty((int) $vars['deleted_by_receiver'])
                ? 0
                : (
                    'draft' === $vars['status']
                    ? 1
                    : 2
                ),
            'target_visit'  => (int) $vars['read_at'],
            'num_replies'   => 0,
            'first_post_id' => 0,
            'last_post'     => (int) $vars['lastedited_at'],
            'last_post_id'  => 0,
            'last_number'   => 0,
        ];
    }

    /*************************************************************************/
    /* pm_posts                                                              */
    /*************************************************************************/
    public function pm_postsPre(DB $db, int $id): ?bool
    {
        $tables = $db->getMap();

        if (empty($tables['pun_pm_messages'])) {
            return null;
        }

        $map = $this->c->dbMapArray['pm_posts'];

        unset($map['id']);

        $sub               = $this->subQInsert($map);
        $this->insertQuery = "INSERT INTO ::pm_posts ({$sub['fields']}) VALUES ({$sub['values']})";

        $vars = [
            ':id'    => $id,
            ':limit' => $this->c->LIMIT,
        ];
        $query = 'SELECT *
            FROM ::pun_pm_messages
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
            'poster'       => '???',
            'poster_id'    => (int) $vars['sender_id'],
            'poster_ip'    => '0.0.0.0',
            'message'      => (string) $vars['body'],
            'hide_smilies' => 0,
            'posted'       => (int) $vars['lastedited_at'],
            'edited'       => 0,
            'topic_id'     => $id,
        ];
    }

    /*************************************************************************/
    /* pm_topics_again                                                       */
    /*************************************************************************/

    /*************************************************************************/
    /* pm_block                                                              */
    /*************************************************************************/

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

        return [
            'username'    => (string) $vars['username'],
            'ip'          => (string) $vars['ip'],
            'email'       => (string) $vars['email'],
            'message'     => (string) $vars['message'],
            'expire'      => (int) $vars['expire'],
            'ban_creator' => (int) $vars['ban_creator'],
        ];
    }

    /*************************************************************************/
    /* config                                                                */
    /*************************************************************************/
    protected array $newConfig;

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
            ['o_default_timezone'      , \date_default_timezone_get()],
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
            ['b_forum_subscriptions'   , '1' == $old['o_subscriptions'] ? 1 : 0],
            ['b_topic_subscriptions'   , '1' == $old['o_subscriptions'] ? 1 : 0],
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
            ['i_feed_type'             , 2],
            ['i_feed_ttl'              , 15],
            ['b_message_bbcode'        , '1' == $old['p_message_bbcode'] ? 1 : 0],
            ['b_message_all_caps'      , '1' == $old['p_message_all_caps'] ? 1 : 0],
            ['b_subject_all_caps'      , '1' == $old['p_subject_all_caps'] ? 1 : 0],
            ['b_sig_all_caps'          , '1' == $old['p_sig_all_caps'] ? 1 : 0],
            ['b_sig_bbcode'            , '1' == $old['p_sig_bbcode'] ? 1 : 0],
            ['b_force_guest_email'     , '1' == $old['p_force_guest_email'] ? 1 : 0],
            ['b_pm'                    , '1' == ($old['o_pms_enabled'] ?? '0') ? 1 : 0],
            ['b_poll_enabled'          , '1' == ($old['o_poll_enabled'] ?? '0') ? 1 : 0],
            ['i_poll_max_questions'    , (int) ($old['o_poll_max_ques'] ?? 3)],
            ['i_poll_max_fields'       , (int) ($old['o_poll_max_field'] ?? 20)],
            ['i_poll_time'             , (int) ($old['o_poll_time'] ?? 60)],
            ['i_poll_term'             , (int) ($old['o_poll_term'] ?? 3)],
            ['b_poll_guest'            , '1' == ($old['o_poll_guest'] ?? '0') ? 1 : 0],
            ['a_max_users'             , \json_encode(
                [
                    'number' => 1,
                    'time'   => \time(),
                ], FORK_JSON_ENCODE
            )],
            ['a_bb_white_mes'          , \json_encode([], FORK_JSON_ENCODE)],
            ['a_bb_white_sig'          , \json_encode(['b', 'i', 'u', 'color', 'colour', 'email', 'url'], FORK_JSON_ENCODE)],
            ['a_bb_black_mes'          , \json_encode([], FORK_JSON_ENCODE)],
            ['a_bb_black_sig'          , \json_encode([], FORK_JSON_ENCODE)],
            ['a_guest_set'             , \json_encode(
                [
                    'show_smilies' => 1,
                    'show_sig'     => 1,
                    'show_avatars' => 1,
                    'show_img'     => 1,
                    'show_img_sig' => 1,
                ], FORK_JSON_ENCODE
            )],
            ['s_РЕГИСТР'               , 'Ok'],
            ['b_oauth_allow'           , 0],
            ['i_avatars_quality'       , 75],
            ['b_upload'                , 0],
            ['i_upload_img_quality'    , 75],
            ['i_upload_img_axis_limit' , 1920],
            ['s_upload_img_outf'       , 'webp,jpg,png,gif'],
            ['i_search_ttl'            , 900],
            ['b_ant_hidden_ch'         , 1],
            ['b_ant_use_js'            , 0],
            ['s_meta_desc'             , ''],
            ['a_og_image'              , \json_encode([], FORK_JSON_ENCODE)],
            ['b_reaction'              , 0],
            ['a_reaction_types'        , \json_encode(
                [
                    1  => ['like', true],
                    2  => ['fire', true],
                    3  => ['lol', true],
                    4  => ['smile', true],
                    5  => ['frown', true],
                    6  => ['sad', true],
                    7  => ['cry', true],
                    8  => ['angry', true],
                    9  => ['dislike', true],
                    10 => ['meh', true],
                    11 => ['shock', true],
                ], FORK_JSON_ENCODE
            )],
            ['b_show_user_reaction'    , 0],
            ['b_default_lang_auto'     , 1],
            ['b_email_use_cron'        , 0],
            ['i_censoring_count'       , 0],
            ['b_hide_guest_email_fld'  , 0],
            ['b_regs_disable_email'    , 0],
            ['b_premoderation'         , 0],
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
}
