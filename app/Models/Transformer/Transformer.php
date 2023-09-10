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
use RuntimeException;
use function \ForkBB\__;

class Transformer extends Model
{
    /**
     * Ключ модели для контейнера
     */
    protected string $cKey = 'Transformer';

    protected array $oldAdd = [
        'categories',
        'forums',
        'groups',
        'posts',
        'topics',
        'pm_posts',
        'pm_topics',
        'users',
        'warnings',
        'attachments',
    ];
    protected array $errors = [];

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function setError(string|array $error): void
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

    public function receiverTest(): int|false
    {
        $count = \count($this->c->DB->getMap());

        if ($count > 0) {
            $driver = $this->loadDriver('ForkBB');
            $result = $driver->test($this->c->DB, true);

            if (true !== $result) {
                if (! empty($result)) {
                    $this->setError($result);
                }

                return false;
            }
        }

        return $count;
    }

    public function step(int $step, int $id): mixed
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

                if (
                    TRANSFORMER_MERGE === $this->c->TR_METHOD
                    && 'config' === $name
                ) {
                    return [
                        'step' => $step + 1,
                        'id'   => 0,
                    ];
                }

                $resultPre = $driver->$pre($this->c->DBSource, $id);

                if (false === $resultPre) {
                    throw new RuntimeException("The {$pre} method returned false");
                } elseif (true === $resultPre) {
                    while (\is_array($row = $driver->$get($newId))) {
                        ++$count;

                        if (false === $driver->$set($this->c->DB, $row)) {
                            throw new RuntimeException("The {$set} method returned false");
                        }
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
                    TRANSFORMER_COPY === $this->c->TR_METHOD
                    || TRANSFORMER_EXACT_COPY === $this->c->TR_METHOD
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
        if ('pgsql' === $this->c->DB->getType()) {
            $query = 'CREATE COLLATION IF NOT EXISTS fork_icu (
                provider = icu,
                locale = \'und-u-ks-level2\'
            )';

            $this->c->DB->exec($query);
        }

        //attachments
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'uid'         => ['INT(10) UNSIGNED', false, 0],
                'created'     => ['INT(10) UNSIGNED', false, 0],
                'size_kb'     => ['INT(10) UNSIGNED', false, 0],
                'path'        => ['VARCHAR(255)', false, ''],
                'uip'         => ['VARCHAR(45)', false, ''],
            ],
            'PRIMARY KEY' => ['id'],
            'INDEXES' => [
                'uid_idx' => ['uid'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::attachments', $schema);

        //attachments_pos
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'pid'         => ['INT(10) UNSIGNED', false, 0],
            ],
            'UNIQUE KEYS' => [
                'id_pid_idx' => ['id', 'pid'],
            ],
            'INDEXES' => [
                'pid_idx' => ['pid'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::attachments_pos', $schema);

        //attachments_pos_pm
        $schema = [
            'FIELDS' => [
                'id'          => ['SERIAL', false],
                'pid'         => ['INT(10) UNSIGNED', false, 0],
            ],
            'UNIQUE KEYS' => [
                'id_pid_idx' => ['id', 'pid'],
            ],
            'INDEXES' => [
                'pid_idx' => ['pid'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::attachments_pos_pm', $schema);

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
                'g_delete_profile'       => ['TINYINT(1)', false, 0],
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
                'g_up_ext'               => ['VARCHAR(255)', false, 'webp,jpg,jpeg,png,gif,avif'],
                'g_up_size_kb'           => ['INT(10) UNSIGNED', false, 0],
                'g_up_limit_mb'          => ['INT(10) UNSIGNED', false, 0],
            ],
            'PRIMARY KEY' => ['g_id'],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::groups', $schema);

        // online
        $schema = [
            'FIELDS' => [
                'user_id'     => ['INT(10) UNSIGNED', false, 0],
                'ident'       => ['VARCHAR(45)', false, ''],
                'logged'      => ['INT(10) UNSIGNED', false, 0],
                'last_post'   => ['INT(10) UNSIGNED', false, 0],
                'last_search' => ['INT(10) UNSIGNED', false, 0],
                'o_position'  => ['VARCHAR(100)', false, ''],
                'o_name'      => ['VARCHAR(190)', false, ''],
            ],
            'UNIQUE KEYS' => [
                'user_id_ident_idx' => ['user_id', 'ident'],
            ],
            'INDEXES' => [
                'logged_idx' => ['logged'],
            ],
            'ENGINE' => $this->c->DBEngine,
        ];
        $this->c->DB->createTable('::online', $schema);

        // posts
        $schema = [
            'FIELDS' => [
                'id'           => ['SERIAL', false],
                'poster'       => ['VARCHAR(190)', false, ''],
                'poster_id'    => ['INT(10) UNSIGNED', false, 0],
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
                'topic_id_idx'  => ['topic_id'],
                'multi_idx'     => ['poster_id', 'topic_id', 'posted'],
                'editor_id_idx' => ['editor_id'],
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
                'multi_idx' => ['word_id', 'post_id'],
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
                'multi_2_idx'       => ['forum_id', 'sticky', 'last_post'],
                'last_post_idx'     => ['last_post'],
                'first_post_id_idx' => ['first_post_id'],
                'multi_1_idx'       => ['moved_to', 'forum_id', 'num_replies', 'last_post'],
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
                'timezone'         => ['VARCHAR(255)', false, \date_default_timezone_get()],
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
                'gender'           => ['TINYINT UNSIGNED', false, FORK_GEN_NOT],
                'u_mark_all_read'  => ['INT(10) UNSIGNED', false, 0],
                'last_report_id'   => ['INT(10) UNSIGNED', false, 0],
                'ip_check_type'    => ['TINYINT UNSIGNED', false, 0],
                'login_ip_cache'   => ['VARCHAR(255)', false, ''],
                'u_up_size_mb'     => ['INT(10) UNSIGNED', false, 0],
                'unfollowed_f'     => ['VARCHAR(255)', false, ''],
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

        // providers
        $schema = [
            'FIELDS' => [
                'pr_name'     => ['VARCHAR(25)', false],
                'pr_allow'    => ['TINYINT(1)', false, 0],
                'pr_pos'      => ['INT(10) UNSIGNED', false, 0],
                'pr_cl_id'    => ['VARCHAR(255)', false, ''],
                'pr_cl_sec'   => ['VARCHAR(255)', false, ''],
            ],
            'UNIQUE KEYS' => [
                'pr_name_idx' => ['pr_name'],
            ],
        ];
        $this->c->DB->createTable('::providers', $schema);

        // providers_users
        $schema = [
            'FIELDS' => [
                'uid'               => ['INT(10) UNSIGNED', false],
                'pr_name'           => ['VARCHAR(25)', false],
                'pu_uid'            => ['VARCHAR(165)', false],
                'pu_email'          => ['VARCHAR(190)', false, ''],
                'pu_email_normal'   => ['VARCHAR(190)', false, ''],
                'pu_email_verified' => ['TINYINT(1)', false, 0],
            ],
            'UNIQUE KEYS' => [
                'pr_name_pu_uid_idx' => ['pr_name', 'pu_uid'],
            ],
            'INDEXES' => [
                'uid_idx'             => ['uid'],
                'pu_email_normal_idx' => ['pu_email_normal'],
            ],
        ];
        $this->c->DB->createTable('::providers_users', $schema);

        // заполнение providers
        $providers = [
            'github', 'yandex', 'google',
        ];

        $query = 'INSERT INTO ::providers (pr_name, pr_pos)
            VALUES (?s:name, ?i:pos)';

        foreach ($providers as $pos => $name) {
            $vars = [
                ':name' => $name,
                ':pos'  => $pos,
            ];

            $this->c->DB->exec($query, $vars);
        }

        // заполнение groups
        $now    = \time();
        $groups = [
            [
                'g_id'                   => FORK_GROUP_ADMIN,
                'g_title'                => __('Administrators'),
                'g_user_title'           => __('Administrator '),
                'g_mod_promote_users'    => 1,
                'g_read_board'           => 1,
                'g_view_users'           => 1,
                'g_post_replies'         => 1,
                'g_post_topics'          => 1,
                'g_edit_posts'           => 1,
                'g_delete_posts'         => 1,
                'g_delete_topics'        => 1,
                'g_post_links'           => 1,
                'g_set_title'            => 1,
                'g_search'               => 1,
                'g_search_users'         => 1,
                'g_send_email'           => 1,
                'g_post_flood'           => 0,
                'g_search_flood'         => 0,
                'g_email_flood'          => 0,
                'g_report_flood'         => 0,
                'g_pm_limit'             => 0,
                'g_sig_length'           => 10000,
                'g_sig_lines'            => 255,
            ],
            [
                'g_id'                   => FORK_GROUP_MOD,
                'g_title'                => __('Moderators'),
                'g_user_title'           => __('Moderator '),
                'g_moderator'            => 1,
                'g_mod_edit_users'       => 1,
                'g_mod_rename_users'     => 1,
                'g_mod_change_passwords' => 1,
                'g_mod_ban_users'        => 1,
                'g_mod_promote_users'    => 1,
                'g_read_board'           => 1,
                'g_view_users'           => 1,
                'g_post_replies'         => 1,
                'g_post_topics'          => 1,
                'g_edit_posts'           => 1,
                'g_delete_posts'         => 1,
                'g_delete_topics'        => 1,
                'g_post_links'           => 1,
                'g_set_title'            => 1,
                'g_search'               => 1,
                'g_search_users'         => 1,
                'g_send_email'           => 1,
                'g_post_flood'           => 0,
                'g_search_flood'         => 0,
                'g_email_flood'          => 0,
                'g_report_flood'         => 0,
            ],
            [
                'g_id'                   => FORK_GROUP_GUEST,
                'g_title'                => __('Guests'),
                'g_user_title'           => '',
                'g_read_board'           => 1,
                'g_view_users'           => 1,
                'g_post_replies'         => 0,
                'g_post_topics'          => 0,
                'g_edit_posts'           => 0,
                'g_delete_posts'         => 0,
                'g_delete_topics'        => 0,
                'g_post_links'           => 0,
                'g_set_title'            => 0,
                'g_search'               => 1,
                'g_search_users'         => 1,
                'g_send_email'           => 0,
                'g_post_flood'           => 120,
                'g_search_flood'         => 60,
                'g_email_flood'          => 0,
                'g_report_flood'         => 0,
                'g_pm'                   => 0,
                'g_sig_length'           => 0,
                'g_sig_lines'            => 0,
            ],
            [
                'g_id'                   => FORK_GROUP_MEMBER,
                'g_title'                => __('Members'),
                'g_user_title'           => '',
                'g_read_board'           => 1,
                'g_view_users'           => 1,
                'g_post_replies'         => 1,
                'g_post_topics'          => 1,
                'g_edit_posts'           => 1,
                'g_delete_posts'         => 1,
                'g_delete_topics'        => 1,
                'g_post_links'           => 1,
                'g_set_title'            => 0,
                'g_search'               => 1,
                'g_search_users'         => 1,
                'g_send_email'           => 1,
                'g_post_flood'           => 30,
                'g_search_flood'         => 30,
                'g_email_flood'          => 60,
                'g_report_flood'         => 60,
            ],
            [
                'g_id'                   => FORK_GROUP_NEW_MEMBER,
                'g_title'                => __('New members'),
                'g_user_title'           => __('New member'),
                'g_read_board'           => 1,
                'g_view_users'           => 1,
                'g_post_replies'         => 1,
                'g_post_topics'          => 1,
                'g_edit_posts'           => 1,
                'g_delete_posts'         => 1,
                'g_delete_topics'        => 1,
                'g_post_links'           => 0,
                'g_set_title'            => 0,
                'g_search'               => 1,
                'g_search_users'         => 1,
                'g_send_email'           => 1,
                'g_post_flood'           => 60,
                'g_search_flood'         => 30,
                'g_email_flood'          => 120,
                'g_report_flood'         => 60,
                'g_deledit_interval'     => 600,
                'g_pm'                   => 0,
                'g_promote_min_posts'    => 5,
                'g_promote_next_group'   => FORK_GROUP_MEMBER,
            ],
        ];

        foreach ($groups as $group) {

            $fields = [];
            $values = [];

            foreach ($group as $key => $value) {
                $fields[] = $key;
                $values[] = (\is_int($value) ? '?i:' : '?s:') . $key;
            }

            $fields = \implode(', ', $fields);
            $values = \implode(', ', $values);
            $query  = "INSERT INTO ::groups ({$fields}) VALUES ({$values})";

            $this->c->DB->exec($query, $group);
        }

        if ('pgsql' === $this->c->DB->getType()) {
            $this->c->DB->exec('ALTER SEQUENCE ::groups_g_id_seq RESTART WITH ' . (FORK_GROUP_NEW_MEMBER + 1));
        }

/*
        $forkConfig = [
            'i_fork_revision'         => $this->c->FORK_REVISION,
            'o_board_title'           => $v->title,
            'o_board_desc'            => $v->descr,
            'o_default_timezone'      => \date_default_timezone_get(),
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
            'b_avatars'               => \filter_var(\ini_get('file_uploads'), \FILTER_VALIDATE_BOOL) ? 1 : 0,
            'o_avatars_dir'           => '/img/avatars',
            'i_avatars_width'         => 160,
            'i_avatars_height'        => 160,
            'i_avatars_size'          => 51200,
            'i_avatars_quality'       => 75,
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
            'b_oauth_allow'           => 0,
            'b_announcement'          => 0,
            'o_announcement_message'  => __('Announcement '),
            'b_rules'                 => 0,
            'o_rules_message'         => __('Rules '),
            'b_maintenance'           => 0,
            'o_maintenance_message'   => __('Maintenance message '),
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
            'a_max_users'             => \json_encode(['number' => 1, 'time' => \time()], FORK_JSON_ENCODE),
            'a_bb_white_mes'          => \json_encode([], FORK_JSON_ENCODE),
            'a_bb_white_sig'          => \json_encode(['b', 'i', 'u', 'color', 'colour', 'email', 'url'], FORK_JSON_ENCODE),
            'a_bb_black_mes'          => \json_encode([], FORK_JSON_ENCODE),
            'a_bb_black_sig'          => \json_encode([], FORK_JSON_ENCODE),
            'a_guest_set'             => \json_encode(
                [
                    'show_smilies' => 1,
                    'show_sig'     => 1,
                    'show_avatars' => 1,
                    'show_img'     => 1,
                    'show_img_sig' => 1,
                ], FORK_JSON_ENCODE
            ),
            's_РЕГИСТР'               => 'Ok',
            'b_upload'                => 0,
            'i_upload_img_quality'    => 75,
            'i_upload_img_axis_limit' => 1920,
            's_upload_img_outf'       => 'webp,jpg,png,gif',
            'i_search_ttl'            => 900,
            'b_ant_hidden_ch'         => 1,
            'b_ant_use_js'            => 0,
            's_meta_desc'             => '',
            'a_og_image'              => \json_encode([], FORK_JSON_ENCODE),
        ];

        foreach ($forkConfig as $name => $value) {
            $this->c->DB->exec('INSERT INTO ::config (conf_name, conf_value) VALUES (?s, ?s)', [$name, $value]);
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
        $bbcodes = include $this->c->DIR_CONFIG . '/defaultBBCode.php';

        foreach ($bbcodes as $bbcode) {
            $vars = [
                ':tag'       => $bbcode['tag'],
                ':structure' => \json_encode($bbcode, FORK_JSON_ENCODE),
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

            $query = "SELECT COUNT(id_old) FROM ::{$name} WHERE id_old>0";

            if ($this->c->DB->query($query)->fetchColumn() > 0) {
                throw new RuntimeException("The id_old field of the {$name} table contains values other than 0 upon initialization (Most likely the previous merge attempt failed)");
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
