<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Transformer;

use ForkBB\Models\Model;
use ForkBB\Models\Transformer\Driver\AbstractDriver;
use InvalidArgumentException;
use RuntimeException;
use function \ForkBB\__;

class Transformer extends Model
{
    const JSON_OPTIONS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * Ключ модели для контейнера
     * @var string
     */
    protected $cKey = 'Transformer';

    /**
     * @var array
     */
    protected $oldAdd = [
        'categories',
        'forums',
        'groups',
        'posts',
        'topics',
        'pm_posts',
        'pm_topics',
        'users',
        'warnings',
    ];

    /**
     * @var array
     */
    protected $errors = [];

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setError(/* string|array */ $error): void
    {
        $this->errors[] = $error;
    }

    protected function loadDriver(string $type): AbstractDriver
    {
        $name   = $this->c->DRIVERS[$type];
        $driver = $this->c->$name;

        if ($type !== $driver->getType()) {
            throw new RuntimeException("Driver '{$name}' incorrectly returns its type");
        }

        return $driver;
    }

    public function sourceTest(): ?array
    {
        $source = $this->c->isInit('DBSource') ? $this->c->DBSource : $this->c->DB;

        if (empty($source->getMap())) {
            $this->setError('This database is empty');

            return null;
        }

        $error  = 0;

        foreach ($this->c->DRIVERS as $type => $name) {
            $driver = $this->loadDriver($type);
            $result = $driver->test($source);

            if (true === $result) {
                return [
                    'type' => $type,
                ];
            } elseif (false === $result) {
                continue;
            }

            $this->setError($result);

            ++$error;
        }

        if (0 === $error) {
            $this->setError('Database belongs to unknown forum type');
        }

        return null;
    }

    public function receiverTest() /* : false|int */
    {
        $count = \count($this->c->DB->getMap());

        if ($count > 0) {
            $driver = $this->loadDriver('ForkBB');
            $result = $driver->test($this->c->DB);

            if (true !== $result) {
                if (! empty($result)) {
                    $this->setError($result);
                }

                return false;
            }
        }

        return $count;
    }

    public function step(int $step, int $id) /* : mixed */
    {
        $driver  = $this->loadDriver($this->c->SOURCE_TYPE);
        $endStep = \max(\array_keys($this->c->STEPS));

        switch ($step) {
            case 0:
                $result = $this->schemaSetup($id);

                if (null === $result) {
                    $step = 1;
                    $id   = 0;
                } else {
                    $id   = $result;
                }

                break;
            case $endStep:
                $this->schemaReModification();

                $step = -1;
                $id   = 0;

                break;
            default:
                if (! isset($this->c->STEPS[$step])) {
                    throw new RuntimeException("Step number {$step} is not specified");
                }

                $count = 0;
                $newId = $id;
                $name  = $this->c->STEPS[$step];
                $pre   = $name . 'Pre';
                $get   = $name . 'Get';
                $set   = $name . 'Set';
                $end   = $name . 'End';

                if (false === $driver->$pre($this->c->DBSource, $id)) {
                    throw new RuntimeException("The {$pre} method returned false");
                }

                while (\is_array($row = $driver->$get($newId))) {
                    ++$count;

                    if (false === $driver->$set($this->c->DB, $row)) {
                        throw new RuntimeException("The {$set} method returned false");
                    }
                }

                if (
                    0 === $count
                    || $newId < 0
                ) {
                    if (false === $driver->$end($this->c->DB)) {
                        throw new RuntimeException("The {$end} method returned false");
                    }

                    $step += 1;
                    $id    = 0;
                } else {
                    $id = $newId + 1;
                }

                break;
        }

        return [
            'step' => $step,
            'id'   => $id,
        ];
    }

    protected function schemaSetup(int $id): ?int
    {
        switch ($id) {
            case 0:
                if (
                    TRANSFORMER_MOVE === $this->c->TR_METHOD
                    || TRANSFORMER_COPY === $this->c->TR_METHOD
                ) {
                    $this->schemaCreate();
                }

                $id = 1;

                break;
            case 1:
                $this->schemaModification();

                $id = null;

                break;
            default:
                throw new RuntimeException("ID number {$id} is not specified");
        }

        return $id;
    }

    protected function schemaCreate(): void
    {
        //$this->c->SOURCE_TYPE
        //$this->c->TR_METHOD

        // bans
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'username'    => ['VARCHAR(190)', false, ''],
                'ip'          => ['VARCHAR(255)', false, ''],
                'email'       => ['VARCHAR(190)', false, ''],
                'message'     => ['VARCHAR(255)', false, ''],
                'expire'      => ['INT(10) UNSIGNED', false, 0],
                'ban_creator' => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'username_idx' => ['username(25)'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::bans', $schema);

        // bbcode
        $schema = [
            'FIELDS' => [
                'id'           => ['SERIAL', false],
                'bb_tag'       => ['VARCHAR(11)', false, ''],
                'bb_edit'      => ['TINYINT(1)', false, 1],
                'bb_delete'    => ['TINYINT(1)', false, 1],
                'bb_structure' => ['MEDIUMTEXT', false],
            ],
            'PRIMARY KEY' => ['id'],
            'UNIQUE KEYS' => [
                'bb_tag_idx' => ['bb_tag'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::bbcode', $schema);

        // categories
        $schema = [
            'FIELDS' => [
                'id'            => ['SERIAL', false],
                'cat_name'      => ['VARCHAR(80)', false, 'New Category'],
                'disp_position' => ['INT(10)', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::categories', $schema);

        // censoring
        $schema = [
            'FIELDS' => [
                'id'           => ['SERIAL', false],
                'search_for'   => ['VARCHAR(60)', false, ''],
                'replace_with' => ['VARCHAR(60)', false, ''],
            ],
            'PRIMARY KEY' => ['id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::censoring', $schema);

        // config
        $schema = [
            'FIELDS' => [
                'conf_name'  => ['VARCHAR(190)', false, ''],
                'conf_value' => ['TEXT', true],
            ],
            'PRIMARY KEY' => ['conf_name'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::config', $schema);

        // forum_perms
        $schema = [
            'FIELDS' => [
                'group_id'     => ['INT(10)', false, 0],
                'forum_id'     => ['INT(10)', false, 0],
                'read_forum'   => ['TINYINT(1)', false, 1],
                'post_replies' => ['TINYINT(1)', false, 1],
                'post_topics'  => ['TINYINT(1)', false, 1],
            ],
            'PRIMARY KEY' => ['group_id', 'forum_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::forum_perms', $schema);

        // forums
        $schema = [
            'FIELDS' => [
                'id'              => ['SERIAL', false],
                'forum_name'      => ['VARCHAR(80)', false, 'New forum'],
                'forum_desc'      => ['TEXT', false],
                'redirect_url'    => ['VARCHAR(255)', false, ''],
                'moderators'      => ['TEXT', false],
                'num_topics'      => ['MEDIUMINT(8) UNSIGNED', false, 0],
                'num_posts'       => ['MEDIUMINT(8) UNSIGNED', false, 0],
                'last_post'       => ['INT(10) UNSIGNED', false, 0],
                'last_post_id'    => ['INT(10) UNSIGNED', false, 0],
                'last_poster'     => ['VARCHAR(190)', false, ''],
                'last_poster_id'  => ['INT(10) UNSIGNED', false, 0],
                'last_topic'      => ['VARCHAR(255)', false, ''],
                'sort_by'         => ['TINYINT(1)', false, 0],
                'disp_position'   => ['INT(10)', false, 0],
                'cat_id'          => ['INT(10) UNSIGNED', false, 0],
                'no_sum_mess'     => ['TINYINT(1)', false, 0],
                'parent_forum_id' => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::forums', $schema);

        // groups
        $schema = [
            'FIELDS' => [
                'g_id'                   => ['SERIAL', false],
                'g_title'                => ['VARCHAR(50)', false, ''],
                'g_user_title'           => ['VARCHAR(50)', false, ''],
                'g_promote_min_posts'    => ['INT(10) UNSIGNED', false, 0],
                'g_promote_next_group'   => ['INT(10) UNSIGNED', false, 0],
                'g_moderator'            => ['TINYINT(1)', false, 0],
                'g_mod_edit_users'       => ['TINYINT(1)', false, 0],
                'g_mod_rename_users'     => ['TINYINT(1)', false, 0],
                'g_mod_change_passwords' => ['TINYINT(1)', false, 0],
                'g_mod_ban_users'        => ['TINYINT(1)', false, 0],
                'g_mod_promote_users'    => ['TINYINT(1)', false, 0],
                'g_read_board'           => ['TINYINT(1)', false, 1],
                'g_view_users'           => ['TINYINT(1)', false, 1],
                'g_post_replies'         => ['TINYINT(1)', false, 1],
                'g_post_topics'          => ['TINYINT(1)', false, 1],
                'g_edit_posts'           => ['TINYINT(1)', false, 1],
                'g_delete_posts'         => ['TINYINT(1)', false, 1],
                'g_delete_topics'        => ['TINYINT(1)', false, 1],
                'g_post_links'           => ['TINYINT(1)', false, 1],
                'g_set_title'            => ['TINYINT(1)', false, 1],
                'g_search'               => ['TINYINT(1)', false, 1],
                'g_search_users'         => ['TINYINT(1)', false, 1],
                'g_send_email'           => ['TINYINT(1)', false, 1],
                'g_post_flood'           => ['SMALLINT(6)', false, 30],
                'g_search_flood'         => ['SMALLINT(6)', false, 30],
                'g_email_flood'          => ['SMALLINT(6)', false, 60],
                'g_report_flood'         => ['SMALLINT(6)', false, 60],
                'g_deledit_interval'     => ['INT(10)', false, 0],
                'g_pm'                   => ['TINYINT(1)', false, 1],
                'g_pm_limit'             => ['INT(10) UNSIGNED', false, 100],
                'g_sig_length'           => ['SMALLINT UNSIGNED', false, 400],
                'g_sig_lines'            => ['TINYINT UNSIGNED', false, 4],
            ],
            'PRIMARY KEY' => ['g_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::groups', $schema);

        // online
        $schema = [
            'FIELDS' => [
                'user_id'     => ['INT(10) UNSIGNED', false, 1],
                'ident'       => ['VARCHAR(190)', false, ''],
                'logged'      => ['INT(10) UNSIGNED', false, 0],
                'last_post'   => ['INT(10) UNSIGNED', false, 0],
                'last_search' => ['INT(10) UNSIGNED', false, 0],
                'o_position'  => ['VARCHAR(100)', false, ''],
                'o_name'      => ['VARCHAR(190)', false, ''],
            ],
            'UNIQUE KEYS' => [
                'user_id_ident_idx' => ['user_id', 'ident(45)'],
            ],
            'INDEXES' => [
                'ident_idx'      => ['ident'],
                'logged_idx'     => ['logged'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::online', $schema);

        // posts
        $schema = [
            'FIELDS' => [
                'id'           => ['SERIAL', false],
                'poster'       => ['VARCHAR(190)', false, ''],
                'poster_id'    => ['INT(10) UNSIGNED', false, 1],
                'poster_ip'    => ['VARCHAR(45)', false, ''],
                'poster_email' => ['VARCHAR(190)', false, ''],
                'message'      => ['MEDIUMTEXT', false],
                'hide_smilies' => ['TINYINT(1)', false, 0],
                'edit_post'    => ['TINYINT(1)', false, 0],
                'posted'       => ['INT(10) UNSIGNED', false, 0],
                'edited'       => ['INT(10) UNSIGNED', false, 0],
                'editor'       => ['VARCHAR(190)', false, ''],
                'editor_id'    => ['INT(10) UNSIGNED', false, 0],
                'user_agent'   => ['VARCHAR(255)', false, ''],
                'topic_id'     => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'topic_id_idx' => ['topic_id'],
                'multi_idx'    => ['poster_id', 'topic_id'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::posts', $schema);

        // reports
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'post_id'     => ['INT(10) UNSIGNED', false, 0],
                'topic_id'    => ['INT(10) UNSIGNED', false, 0],
                'forum_id'    => ['INT(10) UNSIGNED', false, 0],
                'reported_by' => ['INT(10) UNSIGNED', false, 0],
                'created'     => ['INT(10) UNSIGNED', false, 0],
                'message'     => ['TEXT', false],
                'zapped'      => ['INT(10) UNSIGNED', false, 0],
                'zapped_by'   => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'zapped_idx' => ['zapped'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::reports', $schema);

        // search_cache
        $schema = [
            'FIELDS' => [
                'search_data' => ['MEDIUMTEXT', false],
                'search_time' => ['INT(10) UNSIGNED', false, 0],
                'search_key'  => ['VARCHAR(190)', false, '', 'bin'],
            ],
            'INDEXES' => [
                'search_time_idx' => ['search_time'],
                'search_key_idx'  => ['search_key'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::search_cache', $schema);

        // search_matches
        $schema = [
            'FIELDS' => [
                'post_id'       => ['INT(10) UNSIGNED', false, 0],
                'word_id'       => ['INT(10) UNSIGNED', false, 0],
                'subject_match' => ['TINYINT(1)', false, 0],
            ],
            'INDEXES' => [
                'word_id_idx' => ['word_id'],
                'post_id_idx' => ['post_id'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::search_matches', $schema);

        // search_words
        $schema = [
            'FIELDS' => [
                'id'   => ['SERIAL', false],
                'word' => ['VARCHAR(20)', false, '' , 'bin'],
            ],
            'PRIMARY KEY' => ['id'],
            'UNIQUE KEYS' => [
                'word_idx' => ['word']
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::search_words', $schema);

        // topic_subscriptions
        $schema = [
            'FIELDS' => [
                'user_id'  => ['INT(10) UNSIGNED', false, 0],
                'topic_id' => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['user_id', 'topic_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::topic_subscriptions', $schema);

        // forum_subscriptions
        $schema = [
            'FIELDS' => [
                'user_id'  => ['INT(10) UNSIGNED', false, 0],
                'forum_id' => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['user_id', 'forum_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::forum_subscriptions', $schema);

        // topics
        $schema = [
            'FIELDS' => [
                'id'             => ['SERIAL', false],
                'poster'         => ['VARCHAR(190)', false, ''],
                'poster_id'      => ['INT(10) UNSIGNED', false, 0],
                'subject'        => ['VARCHAR(255)', false, ''],
                'posted'         => ['INT(10) UNSIGNED', false, 0],
                'first_post_id'  => ['INT(10) UNSIGNED', false, 0],
                'last_post'      => ['INT(10) UNSIGNED', false, 0],
                'last_post_id'   => ['INT(10) UNSIGNED', false, 0],
                'last_poster'    => ['VARCHAR(190)', false, ''],
                'last_poster_id' => ['INT(10) UNSIGNED', false, 0],
                'num_views'      => ['MEDIUMINT(8) UNSIGNED', false, 0],
                'num_replies'    => ['MEDIUMINT(8) UNSIGNED', false, 0],
                'closed'         => ['TINYINT(1)', false, 0],
                'sticky'         => ['TINYINT(1)', false, 0],
                'stick_fp'       => ['TINYINT(1)', false, 0],
                'moved_to'       => ['INT(10) UNSIGNED', false, 0],
                'forum_id'       => ['INT(10) UNSIGNED', false, 0],
                'poll_type'      => ['SMALLINT UNSIGNED', false, 0],
                'poll_time'      => ['INT(10) UNSIGNED', false, 0],
                'poll_term'      => ['TINYINT', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'forum_id_idx'      => ['forum_id'],
                'moved_to_idx'      => ['moved_to'],
                'last_post_idx'     => ['last_post'],
                'first_post_id_idx' => ['first_post_id'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::topics', $schema);

        // pm_block
        $schema = [
            'FIELDS' => [
                'bl_first_id'  => ['INT(10) UNSIGNED', false, 0],
                'bl_second_id' => ['INT(10) UNSIGNED', false, 0],
            ],
            'INDEXES' => [
                'bl_first_id_idx'  => ['bl_first_id'],
                'bl_second_id_idx' => ['bl_second_id'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::pm_block', $schema);

        // pm_posts
        $schema = [
            'FIELDS' => [
                'id'           => ['SERIAL', false],
                'poster'       => ['VARCHAR(190)', false, ''],
                'poster_id'    => ['INT(10) UNSIGNED', false, 0],
                'poster_ip'    => ['VARCHAR(45)', false, ''],
                'message'      => ['TEXT', false],
                'hide_smilies' => ['TINYINT(1)', false, 0],
                'posted'       => ['INT(10) UNSIGNED', false, 0],
                'edited'       => ['INT(10) UNSIGNED', false, 0],
                'topic_id'     => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'topic_id_idx' => ['topic_id'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::pm_posts', $schema);

        // pm_topics
        $schema = [
            'FIELDS' => [
                'id'            => ['SERIAL', false],
                'subject'       => ['VARCHAR(255)', false, ''],
                'poster'        => ['VARCHAR(190)', false, ''],
                'poster_id'     => ['INT(10) UNSIGNED', false, 0],
                'poster_status' => ['TINYINT UNSIGNED', false, 0],
                'poster_visit'  => ['INT(10) UNSIGNED', false, 0],
                'target'        => ['VARCHAR(190)', false, ''],
                'target_id'     => ['INT(10) UNSIGNED', false, 0],
                'target_status' => ['TINYINT UNSIGNED', false, 0],
                'target_visit'  => ['INT(10) UNSIGNED', false, 0],
                'num_replies'   => ['INT(10) UNSIGNED', false, 0],
                'first_post_id' => ['INT(10) UNSIGNED', false, 0],
                'last_post'     => ['INT(10) UNSIGNED', false, 0],
                'last_post_id'  => ['INT(10) UNSIGNED', false, 0],
                'last_number'   => ['TINYINT UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'last_post_idx'        => ['last_post'],
                'poster_id_status_idx' => ['poster_id', 'poster_status'],
                'target_id_status_idx' => ['target_id', 'target_status'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::pm_topics', $schema);

        // users
        $schema = [
            'FIELDS' => [
                'id'               => ['SERIAL', false],
                'group_id'         => ['INT(10) UNSIGNED', false, 0],
                'username'         => ['VARCHAR(190)', false, ''],
                'username_normal'  => ['VARCHAR(190)', false, ''],
                'password'         => ['VARCHAR(255)', false, ''],
                'email'            => ['VARCHAR(190)', false, ''],
                'email_normal'     => ['VARCHAR(190)', false, ''],
                'email_confirmed'  => ['TINYINT(1)', false, 0],
                'title'            => ['VARCHAR(50)', false, ''],
                'avatar'           => ['VARCHAR(30)', false, ''],
                'realname'         => ['VARCHAR(40)', false, ''],
                'url'              => ['VARCHAR(100)', false, ''],
                'jabber'           => ['VARCHAR(80)', false, ''],
                'icq'              => ['VARCHAR(12)', false, ''],
                'msn'              => ['VARCHAR(80)', false, ''],
                'aim'              => ['VARCHAR(30)', false, ''],
                'yahoo'            => ['VARCHAR(30)', false, ''],
                'location'         => ['VARCHAR(30)', false, ''],
                'signature'        => ['TEXT', false],
                'disp_topics'      => ['TINYINT UNSIGNED', false, 0],
                'disp_posts'       => ['TINYINT UNSIGNED', false, 0],
                'email_setting'    => ['TINYINT(1)', false, 1],
                'notify_with_post' => ['TINYINT(1)', false, 0],
                'auto_notify'      => ['TINYINT(1)', false, 0],
                'show_smilies'     => ['TINYINT(1)', false, 1],
                'show_img'         => ['TINYINT(1)', false, 1],
                'show_img_sig'     => ['TINYINT(1)', false, 1],
                'show_avatars'     => ['TINYINT(1)', false, 1],
                'show_sig'         => ['TINYINT(1)', false, 1],
                'timezone'         => ['FLOAT', false, 0],
                'dst'              => ['TINYINT(1)', false, 0],
                'time_format'      => ['TINYINT(1)', false, 0],
                'date_format'      => ['TINYINT(1)', false, 0],
                'language'         => ['VARCHAR(25)', false, ''],
                'style'            => ['VARCHAR(25)', false, ''],
                'num_posts'        => ['INT(10) UNSIGNED', false, 0],
                'num_topics'       => ['INT(10) UNSIGNED', false, 0],
                'last_post'        => ['INT(10) UNSIGNED', false, 0],
                'last_search'      => ['INT(10) UNSIGNED', false, 0],
                'last_email_sent'  => ['INT(10) UNSIGNED', false, 0],
                'last_report_sent' => ['INT(10) UNSIGNED', false, 0],
                'registered'       => ['INT(10) UNSIGNED', false, 0],
                'registration_ip'  => ['VARCHAR(45)', false, ''],
                'last_visit'       => ['INT(10) UNSIGNED', false, 0],
                'admin_note'       => ['VARCHAR(30)', false, ''],
                'activate_string'  => ['VARCHAR(80)', false, ''],
                'u_pm'             => ['TINYINT(1)', false, 1],
                'u_pm_notify'      => ['TINYINT(1)', false, 0],
                'u_pm_flash'       => ['TINYINT(1)', false, 0],
                'u_pm_num_new'     => ['INT(10) UNSIGNED', false, 0],
                'u_pm_num_all'     => ['INT(10) UNSIGNED', false, 0],
                'u_pm_last_post'   => ['INT(10) UNSIGNED', false, 0],
                'warning_flag'     => ['TINYINT(1)', false, 0],
                'warning_all'      => ['INT(10) UNSIGNED', false, 0],
                'gender'           => ['TINYINT UNSIGNED', false, 0],
                'u_mark_all_read'  => ['INT(10) UNSIGNED', false, 0],
                'last_report_id'   => ['INT(10) UNSIGNED', false, 0],
                'ip_check_type'    => ['TINYINT UNSIGNED', false, 0],
                'login_ip_cache'   => ['VARCHAR(255)', false, ''],
            ],
            'PRIMARY KEY' => ['id'],
            'UNIQUE KEYS' => [
                'username_idx'     => ['username(25)'],
                'email_normal_idx' => ['email_normal'],
            ],
            'INDEXES' => [
                'registered_idx' => ['registered'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::users', $schema);

        // smilies
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'sm_image'    => ['VARCHAR(40)', false, ''],
                'sm_code'     => ['VARCHAR(20)', false, ''],
                'sm_position' => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::smilies', $schema);

        // warnings
        $schema = [
            'FIELDS' => [
                'id'        => ['SERIAL', false],
                'poster'    => ['VARCHAR(190)', false, ''],
                'poster_id' => ['INT(10) UNSIGNED', false, 0],
                'posted'    => ['INT(10) UNSIGNED', false, 0],
                'message'   => ['TEXT', false],
            ],
            'PRIMARY KEY' => ['id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::warnings', $schema);

        // poll
        $schema = [
            'FIELDS' => [
                'tid'         => ['INT(10) UNSIGNED', false, 0],
                'question_id' => ['TINYINT', false, 0],
                'field_id'    => ['TINYINT', false, 0],
                'qna_text'    => ['VARCHAR(255)', false, ''],
                'votes'       => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['tid', 'question_id', 'field_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::poll', $schema);

        // poll_voted
        $schema = [
            'FIELDS' => [
                'tid' => ['INT(10) UNSIGNED', false],
                'uid' => ['INT(10) UNSIGNED', false],
                'rez' => ['TEXT', false],
            ],
            'PRIMARY KEY' => ['tid', 'uid'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::poll_voted', $schema) ;

        // mark_of_forum
        $schema = [
            'FIELDS' => [
                'uid'              => ['INT(10) UNSIGNED', false, 0],
                'fid'              => ['INT(10) UNSIGNED', false, 0],
                'mf_mark_all_read' => ['INT(10) UNSIGNED', false, 0],
            ],
            'UNIQUE KEYS' => [
                'uid_fid_idx' => ['uid', 'fid'],
            ],
            'INDEXES' => [
                'mf_mark_all_read_idx' => ['mf_mark_all_read'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::mark_of_forum', $schema);

        // mark_of_topic
        $schema = [
            'FIELDS' => [
                'uid'           => ['INT(10) UNSIGNED', false, 0],
                'tid'           => ['INT(10) UNSIGNED', false, 0],
                'mt_last_visit' => ['INT(10) UNSIGNED', false, 0],
                'mt_last_read'  => ['INT(10) UNSIGNED', false, 0],
            ],
            'UNIQUE KEYS' => [
                'uid_tid_idx' => ['uid', 'tid'],
            ],
            'INDEXES' => [
                'mt_last_visit_idx' => ['mt_last_visit'],
                'mt_last_read_idx'  => ['mt_last_read'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::mark_of_topic', $schema);

        $now    = \time();
        $groups = [
            // g_id,                g_title,              g_user_title,        g_moderator, g_mod_edit_users, g_mod_rename_users, g_mod_change_passwords, g_mod_ban_users, g_mod_promote_users, g_read_board, g_view_users, g_post_replies, g_post_topics, g_edit_posts, g_delete_posts, g_delete_topics, g_post_links, g_set_title, g_search, g_search_users, g_send_email, g_post_flood, g_search_flood, g_email_flood, g_report_flood, g_promote_min_posts, g_promote_next_group
            [FORK_GROUP_ADMIN,      __('Administrators'), __('Administrator '), 0,          0,                0,                  0,                      0,               1,                   1,            1,            1,              1,             1,            1,              1,               1,            1,           1,        1,              1,            0,            0,              0,             0,              0,                   0,                     ],
            [FORK_GROUP_MOD,        __('Moderators'),     __('Moderator '),     1,          1,                1,                  1,                      1,               1,                   1,            1,            1,              1,             1,            1,              1,               1,            1,           1,        1,              1,            0,            0,              0,             0,              0,                   0,                     ],
            [FORK_GROUP_GUEST,      __('Guests'),         '',                   0,          0,                0,                  0,                      0,               0,                   1,            1,            0,              0,             0,            0,              0,               0,            0,           1,        1,              0,            120,          60,             0,             0,              0,                   0,                     ],
            [FORK_GROUP_MEMBER,     __('Members'),        '',                   0,          0,                0,                  0,                      0,               0,                   1,            1,            1,              1,             1,            1,              1,               1,            0,           1,        1,              1,            30,           30,             60,            60,             0,                   0,                     ],
        ];

        foreach ($groups as $group) {
            $this->c->DB->exec('INSERT INTO ::groups (g_id, g_title, g_user_title, g_moderator, g_mod_edit_users, g_mod_rename_users, g_mod_change_passwords, g_mod_ban_users, g_mod_promote_users, g_read_board, g_view_users, g_post_replies, g_post_topics, g_edit_posts, g_delete_posts, g_delete_topics, g_post_links, g_set_title, g_search, g_search_users, g_send_email, g_post_flood, g_search_flood, g_email_flood, g_report_flood, g_promote_min_posts, g_promote_next_group) VALUES (?i, ?s, ?s, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i, ?i)', $group) ;
        }

        $this->c->DB->exec('UPDATE ::groups SET g_pm_limit=0 WHERE g_id=?i', [FORK_GROUP_ADMIN]);
        $this->c->DB->exec('UPDATE ::groups SET g_pm=0, g_sig_length=0, g_sig_lines=0 WHERE g_id=?i', [FORK_GROUP_GUEST]);

        if ('pgsql' === $this->c->DB->getType()) {
            $this->c->DB->exec('ALTER SEQUENCE ::groups_g_id_seq RESTART WITH ' . (FORK_GROUP_MEMBER + 1));
        }

/*
        $pun_config = [
            'i_fork_revision'         => $this->c->FORK_REVISION,
            'o_board_title'           => $v->title,
            'o_board_desc'            => $v->descr,
            'o_default_timezone'      => 0,
            'i_timeout_visit'         => 3600,
            'i_timeout_online'        => 900,
            'i_redirect_delay'        => 1,
            'b_show_user_info'        => 1,
            'b_show_post_count'       => 1,
            'b_smilies'               => 1,
            'b_smilies_sig'           => 1,
            'b_make_links'            => 1,
            'o_default_lang'          => $v->defaultlang,
            'o_default_style'         => $v->defaultstyle,
            'i_default_user_group'    => FORK_GROUP_NEW_MEMBER,
            'i_topic_review'          => 15,
            'i_disp_topics_default'   => 30,
            'i_disp_posts_default'    => 25,
            'i_disp_users'            => 50,
            'b_quickpost'             => 1,
            'b_users_online'          => 1,
            'b_censoring'             => 0,
            'b_show_dot'              => 0,
            'b_topic_views'           => 1,
            'o_additional_navlinks'   => '',
            'i_report_method'         => 0,
            'b_regs_report'           => 0,
            'i_default_email_setting' => 2,
            'o_mailing_list'          => $v->email,
            'b_avatars'               => \filter_var(@\ini_get('file_uploads'), \FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'o_avatars_dir'           => '/img/avatars',
            'i_avatars_width'         => 60,
            'i_avatars_height'        => 60,
            'i_avatars_size'          => 10240,
            'o_admin_email'           => $v->email,
            'o_webmaster_email'       => $v->email,
            'b_forum_subscriptions'   => 1,
            'b_topic_subscriptions'   => 1,
            'i_email_max_recipients'  => 1,
            'o_smtp_host'             => '',
            'o_smtp_user'             => '',
            'o_smtp_pass'             => '',
            'b_smtp_ssl'              => 0,
            'b_regs_allow'            => 1,
            'b_regs_verify'           => 1,
            'b_announcement'          => 0,
            'o_announcement_message'  => __('Announcement '),
            'b_rules'                 => 0,
            'o_rules_message'         => __('Rules '),
            'b_maintenance'           => 0,
            'o_maintenance_message'   => __('Maintenance message '),
            'b_default_dst'           => 0,
            'i_feed_type'             => 2,
            'i_feed_ttl'              => 0,
            'b_message_bbcode'        => 1,
            'b_message_all_caps'      => 0,
            'b_subject_all_caps'      => 0,
            'b_sig_all_caps'          => 0,
            'b_sig_bbcode'            => 1,
            'b_force_guest_email'     => 1,
            'b_pm'                    => 0,
            'b_poll_enabled'          => 0,
            'i_poll_max_questions'    => 3,
            'i_poll_max_fields'       => 20,
            'i_poll_time'             => 60,
            'i_poll_term'             => 3,
            'b_poll_guest'            => 0,
            'a_max_users'             => \json_encode(['number' => 1, 'time' => \time()], self::JSON_OPTIONS),
            'a_bb_white_mes'          => \json_encode([], self::JSON_OPTIONS),
            'a_bb_white_sig'          => \json_encode(['b', 'i', 'u', 'color', 'colour', 'email', 'url'], self::JSON_OPTIONS),
            'a_bb_black_mes'          => \json_encode([], self::JSON_OPTIONS),
            'a_bb_black_sig'          => \json_encode([], self::JSON_OPTIONS),
            'a_guest_set'             => \json_encode(
                [
                    'show_smilies' => 1,
                    'show_sig'     => 1,
                    'show_avatars' => 1,
                    'show_img'     => 1,
                    'show_img_sig' => 1,
                ], self::JSON_OPTIONS
            ),
            's_РЕГИСТР'               => 'Ok',
        ];

        foreach ($pun_config as $conf_name => $conf_value) {
            $this->c->DB->exec('INSERT INTO ::config (conf_name, conf_value) VALUES (?s, ?s)', [$conf_name, $conf_value]);
        }
*/

        $smilies = [
            ':)'         => 'smile.png',
            '=)'         => 'smile.png',
            ':|'         => 'neutral.png',
            '=|'         => 'neutral.png',
            ':('         => 'sad.png',
            '=('         => 'sad.png',
            ':D'         => 'big_smile.png',
            '=D'         => 'big_smile.png',
            ':o'         => 'yikes.png',
            ':O'         => 'yikes.png',
            ';)'         => 'wink.png',
            ':/'         => 'hmm.png',
            ':P'         => 'tongue.png',
            ':p'         => 'tongue.png',
            ':lol:'      => 'lol.png',
            ':mad:'      => 'mad.png',
            ':rolleyes:' => 'roll.png',
            ':cool:'     => 'cool.png',
        ];
        $i = 0;

        foreach ($smilies as $text => $img) {
            $this->c->DB->exec('INSERT INTO ::smilies (sm_image, sm_code, sm_position) VALUES(?s, ?s, ?i)', [$img, $text, $i++]); //????
        }

        $query   = 'INSERT INTO ::bbcode (bb_tag, bb_edit, bb_delete, bb_structure)
            VALUES(?s:tag, 1, 0, ?s:structure)';
        $bbcodes = include $this->c->DIR_APP . '/config/defaultBBCode.php';

        foreach ($bbcodes as $bbcode) {
            $vars = [
                ':tag'       => $bbcode['tag'],
                ':structure' => \json_encode($bbcode, self::JSON_OPTIONS),
            ];

            $this->c->DB->exec($query, $vars);
        }
    }

    protected function schemaModification(): void
    {
        foreach ($this->oldAdd as $name) {
            if (false === $this->c->DB->addField("::{$name}", 'id_old', 'INT(10) UNSIGNED', false, 0)) {
                throw new RuntimeException("Failed to add 'id_old' field to {$name} table");
            }

            if (false === $this->c->DB->addIndex("::{$name}", 'id_old_idx', ['id_old'])) {
                throw new RuntimeException("Failed to add 'id_old_idx' index to {$name} table");
            }
        }
    }

    protected function schemaReModification(): void
    {
        foreach ($this->oldAdd as $name) {
            if (false === $this->c->DB->dropIndex("::{$name}", 'id_old_idx')) {
                throw new RuntimeException("Failed to drop 'id_old_idx' index to {$name} table");
            }

            if (false === $this->c->DB->dropField("::{$name}", 'id_old')) {
                throw new RuntimeException("Failed to drop 'id_old' field to {$name} table");
            }
        }
    }
}