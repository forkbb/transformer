<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\Pages\Admin;

use ForkBB\Core\Container;
use ForkBB\Core\Validator;
use ForkBB\Models\Page;
use ForkBB\Models\Pages\Admin;
use PDO;
use PDOException;
use RuntimeException;
use function \ForkBB\{__, num, size};

class Install extends Admin
{
    const MYSQL_MIN  = '5.5.3';
    const SQLITE_MIN = '3.25.0';
    const PGSQL_MIN  = '10.0';

    const CACHE_KEY = 'transformer';
    const CACHE_TTL = 1800;

    protected $settings;

    public function __construct(Container $container)
    {
        $this->settings = $container->Cache->get(self::CACHE_KEY, []);

        if (isset($this->settings['lang'])) {
            $container->user->language = $this->settings['lang'];
        }

        parent::__construct($container);

        $container->Lang->load('validator');
        $container->Lang->load('admin_install');

        $this->onlinePos = null;
        $this->nameTpl   = 'layouts/install';
    }

    /**
     * Подготовка страницы к отображению
     */
    public function prepare(): void
    {
    }

    protected function toCache(array $settings): void
    {
        if (true !== $this->c->Cache->set(self::CACHE_KEY, $settings, self::CACHE_TTL)) {
            throw new RuntimeException('Unable to write value to cache');
        }
    }

    protected function verifyKey(array $args, string $dbFlag = null): void
    {
        if (
            ! isset($args['key'], $this->settings['key'])
            || $args['key'] !== $this->settings['key']
        ) {
            $this->fIswev = ['e', 'Script key error'];
        } elseif (
            (
                isset($this->settings['db'])
                && $this->settings['db'] !== $dbFlag
            )
            || (
                ! isset($this->settings['db'])
                && null !== $dbFlag
            )
        ) {
            $this->fIswev = ['e', 'Invalid DB status flag'];
        } else {
            $this->toCache($this->settings);
        }
    }

    /**
     * Возращает доступные типы БД
     */
    protected function DBTypes($source = false): array
    {
        $dbTypes    = [];
        $pdoDrivers = PDO::getAvailableDrivers();

        foreach ($pdoDrivers as $type) {
            if (\is_file($this->c->DIR_APP . '/Core/DB/' . \ucfirst($type) . '.php')) {
                switch ($type) {
                    case 'mysql':
                        if ($source) {
                            $dbTypes[$type]          = 'MySQL (PDO)';
                        } else {
                            $dbTypes['mysql_innodb'] = 'MySQL InnoDB (PDO)';
                            $dbTypes[$type]          = 'MySQL (PDO) (no transactions!)';
                        }

                        break;
                    case 'sqlite':
                        $dbTypes[$type]          = 'SQLite (PDO)';
                        break;
                    case 'pgsql':
                        $dbTypes[$type]          = 'PostgreSQL (PDO)';
                        break;
                    default:
                        $dbTypes[$type]          = \ucfirst($type) . ' (PDO)';
                        break;
                }
            }
        }

        return $dbTypes;
    }

    /**
     * Выбор языка установки
     */
    public function start(array $args, string $method): Page
    {
        // доступность папок на запись
        $folders = [
            $this->c->DIR_CONFIG,
            $this->c->DIR_CONFIG . '/db',
            $this->c->DIR_CACHE,
            $this->c->DIR_PUBLIC . '/img/avatars',
        ];

        foreach ($folders as $folder) {
            if (! \is_writable($folder)) {
                $folder       = \str_replace(\dirname($this->c->DIR_APP), '', $folder);
                $this->fIswev = ['e', ['Alert folder', $folder]];
            }
        }

        // доступность шаблона конфигурации
        $config = \file_get_contents($this->c->DIR_CONFIG . '/main.dist.php');

        if (false === $config) {
            $this->fIswev = ['e', 'No access to main.dist.php'];
        }

        unset($config);

        $langs = $this->c->Func->getNameLangs();

        if (empty($langs)) {
            $this->fIswev = ['e', 'No language packs'];
        }

        if (isset($this->settings['key'])) {
            $this->fIswev = ['e', 'Script runs error'];
        }

        if (
            'POST' === $method
            && empty($this->fIswev['e'])
        ) {
            $v = $this->c->Validator->reset()
                ->addRules([
                    'token'       => 'token:Start',
                    'installlang' => 'required|string:trim',
                    'changelang'  => 'required|string',
                ]);

            if ($v->validation($_POST)) {
                $this->user->language = $v->installlang;

                $key = $this->c->Secury->randomPass(\mt_rand(8,10));

                $this->toCache(
                    [
                        'key'  => $key,
                        'lang' => $this->user->language,
                        'db'   => 'pre',
                    ]
                );

                return $this->c->Redirect->page('Source', ['key' => $key]);
            } else {
                $this->fIswev = $v->getErrors();
            }
        }

        $this->form1 = $this->formStart($langs);

        return $this;
    }

    /**
     * Выбор источника
     */
    public function source(array $args, string $method): Page
    {
        $this->verifyKey($args, 'pre');

        $this->dbTypes = $this->DBTypes(true);

        if (empty($this->dbTypes)) {
            $this->fIswev = ['e', 'No DB extensions'];
        }

        $v = null;

        if (
            'POST' === $method
            && empty($this->fIswev['e'])
        ) {
            $v = $this->c->Validator->reset()
                ->addValidators([
                    'check_prefix' => [$this, 'vCheckPrefix'],
                    'check_host'   => [$this, 'vCheckHost'],
                ])->addRules([
                    'token'        => 'token:Source',
                    'dbtype'       => 'required|string:trim|in:' . \implode(',', \array_keys($this->dbTypes)),
                    'dbhost'       => 'required|string:trim|check_host',
                    'dbname'       => 'required|string:trim',
                    'dbuser'       => 'exist|string:trim',
                    'dbpass'       => 'exist|string:trim',
                    'dbprefix'     => 'exist|string:trim,empty|check_prefix',
                ])->addAliases([
                    'dbtype'       => 'Database type',
                    'dbhost'       => 'Database server hostname',
                    'dbname'       => 'Database name',
                    'dbuser'       => 'Database username',
                    'dbpass'       => 'Database password',
                    'dbprefix'     => 'Table prefix',
                ])->addArguments([
                    'token'        => $args,
                    'dbhost'       => true,
                ])->addMessages([
                ]);

            if ($v->validation($_POST)) {
                $this->settings['source']     = $v->getData();
                $this->settings['sourceInfo'] = $this->sourceInfo;

                unset($this->settings['source']['token']);

                $this->toCache($this->settings);

                return $this->c->Redirect->page('Receiver', $args);
            } else {
                $this->fIswev = $v->getErrors();
            }
        }

        $this->formTitle  = 'Database source setup';
        $this->dbnameHelp = 'For SQLite, the database file...';
        $this->form2      = $this->formDB($v, 'Source', $args);

        return $this;
    }

    /**
     * Выбор получателя
     */
    public function receiver(array $args, string $method): Page
    {
        $this->verifyKey($args, 'pre');

        $this->dbTypes = $this->DBTypes();

        if (empty($this->dbTypes)) {
            $this->fIswev = ['e', 'No DB extensions'];
        }

        $v = null;

        if (
            'POST' === $method
            && empty($this->fIswev['e'])
        ) {
            $v = $this->c->Validator->reset()
                ->addValidators([
                    'check_prefix' => [$this, 'vCheckPrefix'],
                    'check_host'   => [$this, 'vCheckHost'],
                ])->addRules([
                    'token'        => 'token:Receiver',
                    'dbtype'       => 'required|string:trim|in:' . \implode(',', \array_keys($this->dbTypes)),
                    'dbhost'       => 'required|string:trim|check_host',
                    'dbname'       => 'required|string:trim',
                    'dbuser'       => 'exist|string:trim',
                    'dbpass'       => 'exist|string:trim',
                    'dbprefix'     => 'required|string:trim,empty|check_prefix',
                ])->addAliases([
                    'dbtype'       => 'Database type',
                    'dbhost'       => 'Database server hostname',
                    'dbname'       => 'Database name',
                    'dbuser'       => 'Database username',
                    'dbpass'       => 'Database password',
                    'dbprefix'     => 'Table prefix',
                ])->addArguments([
                    'token'        => $args,
                    'dbhost'       => false,
                ])->addMessages([
                ]);

            if ($v->validation($_POST)) {
                $this->settings['receiver']     = $v->getData();
                $this->settings['receiverInfo'] = $this->receiverInfo;

                unset($this->settings['receiver']['token']);

                $this->toCache($this->settings);

                return $this->c->Redirect->page('Confirm', $args);
            } else {
                $this->fIswev = $v->getErrors();
            }
        }

        $this->formTitle = 'Database receiver setup';
        $this->form2     = $this->formDB($v, 'Receiver', $args);

        return $this;
    }

    public function confirm(array $args, string $method): Page
    {
        $this->verifyKey($args, 'pre');

        $v = null;

        if (
            'POST' === $method
            && empty($this->fIswev['e'])
        ) {
            $v = $this->c->Validator->reset()
                ->addValidators([
                ])->addRules([
                    'token'        => 'token:Confirm',
                    'confirm'      => 'integer',
                ])->addAliases([
                ])->addArguments([
                    'token'        => $args,
                ])->addMessages([
                ]);

            if (
                $v->validation($_POST)
                && 1 === $v->confirm
            ) {
                // ??????
                unset($this->settings['db']);

                $this->toCache($this->settings);

                $args = [
                    'key'  => $args['key'],
                    'step' => 0,
                    'id'   => 0,
                ];

                return $this->c->Redirect->page('Step', $args);
            } else {
                $this->fIswev = $v->getErrors();
            }
        }

        $this->fIswev = ['i', 'Backup'];
        $this->form1  = $this->formConfirm($v, $args);
        return $this;
    }

    public function step(array $args, string $method): Page
    {
        $this->verifyKey($args);

        if (! empty($this->fIswev['e'])) {
            return $this;
        }

        if (\function_exists('\\set_time_limit')) {
            \set_time_limit(0);
        }

        $this->toCache($this->settings);
        $this->createDBOptions($this->settings['source']);

        $source = $this->c->DBSource;

        $this->createDBOptions($this->settings['receiver']);

        $db = $this->c->DB;

        $this->c->SOURCE_TYPE = $this->settings['sourceInfo']['type'];
        $this->c->TR_METHOD   = $this->settings['receiverInfo']['method'];
        $this->c->dbMapArray  = $db->getMap();
        $this->c->rUsernames  = $this->settings['usernames'] ?? [];
        $count                = \count($this->c->rUsernames);
        $result               = $this->c->Transformer->step($args['step'], $args['id']);

        if ($count !== \count($this->c->rUsernames)) {
            $this->settings['usernames'] = $this->c->rUsernames;

            $this->toCache($this->settings);
        }

        if (isset($result['step'], $result['id'])) {
            $result['key'] = $args['key'];
            $name          = $this->c->STEPS[$args['step']] ?? '???';
            $marker        = $result['step'] < 0 ? 'Config' : 'Step';

            if (-1 === $result['step']) {
                $this->settings['db'] = 'config';

                $this->toCache($this->settings);
            }

            return $this->c->Redirect->page($marker, $result)
                ->message(['Step %1$s %3$s (%2$s)', $args['step'], $args['id'], $name]);
        }

        return $this;
    }

    public function config(array $args, string $method): Page
    {
        $this->verifyKey($args, 'config');

        if (! empty($this->fIswev['e'])) {
            return $this;
        } elseif (TRANSFORMER_MERGE === $this->settings['receiverInfo']['method']) {
            $this->settings['db'] = 'ok';

            $this->toCache($this->settings);

            return $this->c->Redirect->page('End', $args);
        }

        $this->createDBOptions($this->settings['receiver']);

        $v = null;

        if (
            'POST' === $method
            && empty($this->fIswev['e'])
        ) {
            $v = $this->c->Validator->reset()
                ->addValidators([
                    'rtrim_url'     => [$this, 'vRtrimURL']
                ])->addRules([
                    'token'         => 'token:Config',
                    'baseurl'       => 'required|string:trim|rtrim_url|max:128',
                    'cookie_domain' => 'exist|string:trim|max:128',
                    'cookie_path'   => 'required|string:trim|max:1024',
                    'cookie_secure' => 'required|integer|in:0,1',
                ])->addAliases([
                    'baseurl'       => 'Base URL',
                    'cookie_domain' => 'Cookie Domain',
                    'cookie_path'   => 'Cookie Path',
                    'cookie_secure' => 'Cookie Secure',
                ])->addArguments([
                    'token'        => $args,
                ])->addMessages([
                ]);

            if ($v->validation($_POST)) {
                $config = \file_get_contents($this->c->DIR_CONFIG . '/main.dist.php');

                if (false === $config) {
                    throw new RuntimeException('No access to main.dist.php.');
                }

                $repl = [ //????
                    '_BASE_URL_'      => $v->baseurl,
                    '_DB_DSN_'        => $this->c->DB_DSN,
                    '_DB_USERNAME_'   => $this->c->DB_USERNAME,
                    '_DB_PASSWORD_'   => $this->c->DB_PASSWORD,
                    '_DB_PREFIX_'     => $this->c->DB_PREFIX,
                    '_SALT_FOR_HMAC_' => $this->c->Secury->randomPass(\mt_rand(20,30)),
                    '_COOKIE_PREFIX_' => 'fork' . $this->c->Secury->randomHash(7) . '_',
                    '_COOKIE_DOMAIN_' => $v->cookie_domain,
                    '_COOKIE_PATH_'   => $v->cookie_path,
                    '_COOKIE_SECURE_' => 1 === $v->cookie_secure ? 'true' : 'false',
                    '_COOKIE_KEY1_'   => $this->c->Secury->randomPass(\mt_rand(20,30)),
                    '_COOKIE_KEY2_'   => $this->c->Secury->randomPass(\mt_rand(20,30)),
                ];

                foreach ($repl as $key => $val) {
                    $config = \str_replace($key, \addslashes($val), $config);
                }

                $config = \str_replace('_DB_OPTIONS_', $this->c->DB_OPTS_AS_STR, $config);
                $result = \file_put_contents($this->c->DIR_CONFIG . '/main.php', $config);

                if (false === $result) {
                    throw new RuntimeException('No write to main.php');
                }

                $this->settings['db'] = 'ok';

                $this->toCache($this->settings);

                return $this->c->Redirect->page('End', $args);
            } else {
                $this->fIswev = $v->getErrors();
            }
        }

        $this->fIswev = ['i', 'Database ready'];
        $this->form1  = $this->formConfig($v, $args);

        return $this;
    }

    public function end(array $args, string $method): Page
    {
        $this->verifyKey($args, 'ok');

        if (! empty($this->fIswev['e'])) {
            return $this;
        }

        $this->fIswev = ['s', 'Database ready'];

        if (TRANSFORMER_MERGE !== $this->settings['receiverInfo']['method']) {
            $this->fIswev = ['i', 'Config file is generated'];
        }

        $this->fIswev = ['w', 'Instruction'];

        return $this;
    }

    protected function formStart(array $langs): array
    {
         return [
            'action' => $this->c->Router->link('Start'),
            'hidden' => [
                'token' => $this->c->Csrf->create('Start'),
            ],
            'sets'   => [
                'dlang' => [
                    'fields' => [
                        'installlang' => [
                            'type'    => 'select',
                            'options' => $langs,
                            'value'   => $this->user->language,
                            'caption' => 'Install language',
                            'help'    => 'Choose install language info',
                        ],
                    ],
                ],
            ],
            'btns'   => [
                'changelang'  => [
                    'type'  => 'submit',
                    'value' => __('Select'),
                ],
            ],
        ];
    }

    protected function formDB(?Validator $v, string $marker, array $args): array
    {
        return [
            'action' => $this->c->Router->link($marker, $args),
            'hidden' => [
                'token' => $this->c->Csrf->create($marker, $args),
            ],
            'sets'   => [
                'db-info' => [
                    'info' => [
                        [
                            'value' => __($this->formTitle),
                            'html'  => true,
                        ],
                    ],
                ],
                'db' => [
                    'fields' => [
                        'dbtype' => [
                            'type'     => 'select',
                            'options'  => $this->dbTypes,
                            'value'    => $v ? $v->dbtype : 'mysql_innodb',
                            'caption'  => 'Database type',
                        ],
                        'dbhost' => [
                            'type'     => 'text',
                            'value'    => $v ? $v->dbhost : 'localhost',
                            'caption'  => 'Database server hostname',
                            'required' => true,
                        ],
                        'dbname' => [
                            'type'     => 'text',
                            'value'    => $v ? $v->dbname : '',
                            'caption'  => 'Database name',
                            'help'     => $this->dbnameHelp,
                            'required' => true,
                        ],
                        'dbuser' => [
                            'type'    => 'text',
                            'value'   => $v ? $v->dbuser : '',
                            'caption' => 'Database username',
                        ],
                        'dbpass' => [
                            'type'    => 'password',
                            'value'   => '',
                            'caption' => 'Database password',
                        ],
                        'dbprefix' => [
                            'type'      => 'text',
                            'maxlength' => '40',
                            'value'     => $v ? $v->dbprefix : '',
                            'caption'   => 'Table prefix',
                            'required'  => $marker !== 'Source',
                        ],
                    ],
                ],
            ],
            'btns'   => [
                'submit'  => [
                    'type'  => 'submit',
                    'value' => __('Save'),
                ],
            ],
        ];

    }

    protected function formConfirm(?Validator $v, array $args): array
    {
        $modes = [
            0 => ['COPY', 'Start copy'],
            1 => ['MERGE', 'Start merge'],
            2 => ['EXACT COPY', 'Start copy'],
        ];
        list($method, $btn) = $modes[$this->settings['receiverInfo']['method']];

        $this->createDBOptions($this->settings['source']);

        $sStat = $this->c->DBSource->statistics();

        $this->createDBOptions($this->settings['receiver']);

        $rStat = $this->c->DB->statistics();

        return [
            'action' => $this->c->Router->link('Confirm', $args),
            'hidden' => [
                'token' => $this->c->Csrf->create('Confirm', $args),
            ],
            'sets'   => [
                'mode' => [
                    'class'  => ['data'],
                    'fields' => [
                        'method' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Working mode',
                            'value'   => $method,
                        ],
                    ],
                ],
                'sourceinfo' => [
                    'info' => [
                        [
                            'value' => __('Source legend'),
                        ],
                    ],
                ],
                'source' => [
                    'class'  => ['data'],
                    'legend' => 'Source legend',
                    'fields' => [
                        'board_type' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Board type',
                            'value'   => $this->settings['sourceInfo']['type'],
                        ],
                        'stype' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database type',
                            'value'   => $this->settings['source']['dbtype'],
                        ],
                        'shost' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database server hostname',
                            'value'   => $this->settings['source']['dbhost'],
                        ],
                        'sname' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database name',
                            'value'   => $this->settings['source']['dbname'],
                        ],
                        'srefix' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Table prefix',
                            'value'   => $this->settings['source']['dbprefix'],
                        ],
                        'stables' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Tables',
                            'value'   => $sStat['tables'],
                        ],
                        'srows' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Rows',
                            'value'   => num($sStat['records']),
                        ],
                        'ssize' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Size',
                            'value'   => size($sStat['size']),
                        ],
                    ],
                ],
                'receiverinfo' => [
                    'info' => [
                        [
                            'value' => __('Receiver legend'),
                        ],
                    ],
                ],
                'receiver' => [
                    'class'  => ['data'],
                    'legend' => 'Receiver legend',
                    'fields' => [
                        'rtype' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database type',
                            'value'   => $this->settings['receiver']['dbtype'],
                        ],
                        'rhost' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database server hostname',
                            'value'   => $this->settings['receiver']['dbhost'],
                        ],
                        'rname' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Database name',
                            'value'   => $this->settings['receiver']['dbname'],
                        ],
                        'rprefix' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Table prefix',
                            'value'   => $this->settings['receiver']['dbprefix'],
                        ],
                        'rtables' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Tables',
                            'value'   => $rStat['tables'] ?: '?',
                        ],
                        'rrows' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Rows',
                            'value'   => $rStat['records'] ? num($rStat['records']) : '?',
                        ],
                        'rsize' => [
                            'class'   => ['pline'],
                            'type'    => 'str',
                            'caption' => 'Size',
                            'value'   => $rStat['size'] ? size($rStat['size']) : '?',
                        ],
                    ],
                ],
                'optionsinfo' => [
                    'info' => [
                        [
                            'value' => __('Options'),
                        ],
                    ],
                ],
                'options' => [
                    'class'  => ['data'],
                    'legend' => 'Options',
                    'fields' => [
                        'confirm' => [
                            'class'   => ['pline'],
                            'type'    => 'checkbox',
                            'label'   => 'Confirm action',
                            'checked' => false,
                        ],
                    ],
                ],
            ],
            'btns'   => [
                'submit' => [
                    'type'  => 'submit',
                    'value' => __($btn),
                ],
            ],
        ];

    }

    protected function formConfig(?Validator $v, array $args): array
    {
        return [
            'action' => $this->c->Router->link('Config', $args),
            'hidden' => [
                'token'       => $this->c->Csrf->create('Config', $args),
            ],
            'sets'   => [
                'board-info' => [
                    'info' => [
                        [
                            'value' => __('Board setup'),
                            'html'  => true,
                        ],
                        [
                            'value' => __('Info 11'),
                        ],
                    ],
                ],
                'board' => [
                    'fields' => [
                        'baseurl' => [
                            'type'      => 'text',
                            'maxlength' => '128',
                            'value'     => $v ? $v->baseurl : $this->c->BASE_URL,
                            'caption'   => 'Base URL',
                            'required'  => true,
                        ],
                    ],
                ],
                'cookie-info' => [
                    'info' => [
                        [
                            'value' => __('Cookie setup'),
                            'html'  => true,
                        ],
                        [
                            'value' => __('Info 12'),
                        ],
                    ],
                ],
                'cookie' => [
                    'fields' => [
                        'cookie_domain' => [
                            'type'      => 'text',
                            'maxlength' => '128',
                            'value'     => $v ? $v->cookie_domain : '',
                            'caption'   => 'Cookie Domain',
                            'help'      => 'Cookie Domain info',
                        ],
                        'cookie_path' => [
                            'type'      => 'text',
                            'maxlength' => '1024',
                            'value'     => $v
                                ? $v->cookie_path
                                : \rtrim((string) \parse_url($this->c->BASE_URL, \PHP_URL_PATH), '/') . '/',
                            'caption'   => 'Cookie Path',
                            'help'      => 'Cookie Path info',
                            'required'  => true,
                        ],
                        'cookie_secure' => [
                            'type'    => 'radio',
                            'value'   => $v
                                ? $v->cookie_secure
                                : (
                                    \preg_match('%^https%i', $this->c->BASE_URL)
                                    ? 1
                                    : 0
                                ),
                            'values'  => [1 => __('Yes '), 0 => __('No ')],
                            'caption' => 'Cookie Secure',
                            'help'    => 'Cookie Secure info',
                        ],

                    ],
                ],
            ],
            'btns'   => [
                'submit'  => [
                    'type'  => 'submit',
                    'value' => __('Save'),
                ],
            ],
        ];
    }

    /**
     * Обработка base URL
     */
    public function vRtrimURL(Validator $v, string $url): string
    {
        return \rtrim($url, '/');
    }

    /**
     * Дополнительная проверка префикса
     */
    public function vCheckPrefix(Validator $v, string $prefix): string
    {
        if (! \preg_match('%^[a-z][a-z\d_]*$%i', $prefix)) {
            $v->addError('Table prefix error');
        } elseif (
            'sqlite_' === \strtolower($prefix)
            || 'pg_' === \strtolower($prefix)
        ) {
            $v->addError('Prefix reserved');
        }

        return $prefix;
    }

    /**
     * Полная проверка подключения к БД
     */
    public function vCheckHost(Validator $v, string $dbhost, $attr, $source): string
    {
        $set = [
            'dbtype'   => $v->dbtype,
            'dbhost'   => $dbhost,
            'dbname'   => $v->dbname,
            'dbuser'   => $v->dbuser,
            'dbpass'   => $v->dbpass,
            'dbprefix' => $v->dbprefix,
        ];

        // есть ошибки, ни чего не проверяем
        if (! empty($v->getErrors())) {
            return $dbhost;
        }

        $this->createDBOptions($set);

        // подключение к БД
        try {
            $stat = $this->c->DB->statistics();
        } catch (PDOException $e) {
            $v->addError($e->getMessage());

            return $dbhost;
        }

        $version = $versionNeed = $this->c->DB->getAttribute(PDO::ATTR_SERVER_VERSION);

        switch ($set['dbtype']) {
            case 'mysql_innodb':
            case 'mysql':
                $versionNeed = self::MYSQL_MIN;
                $progName    = 'MySQL';

                break;
            case 'sqlite':
                $versionNeed = self::SQLITE_MIN;
                $progName    = 'SQLite';

                break;
            case 'pgsql':
                $versionNeed = self::PGSQL_MIN;
                $progName    = 'PostgreSQL';

                break;
            }

        if (\version_compare($version, $versionNeed, '<')) {
            $v->addError(['You are running error', $progName, $version, $this->c->FORK_REVISION, $versionNeed]);

            return $dbhost;
        }

        // тест БД источника
        if (true === $source) {
            $this->sourceInfo = $this->c->Transformer->sourceTest();

            if (empty($this->sourceInfo)) {
                foreach ($this->c->Transformer->getErrors() as $error) {
                    $v->addError($error);
                }
            }

            return $dbhost;
        }

        // тест БД получателя
        $result = $this->c->Transformer->receiverTest();

        if (false === $result) {
            foreach ($this->c->Transformer->getErrors() as $error) {
                $v->addError($error);
            }

            $v->addError(['Existing table error', $v->dbprefix, $v->dbname, $stat['tables']]);

            return $dbhost;
        } else {
            $this->receiverInfo = [
                'method' => 0 === $result ? TRANSFORMER_COPY : TRANSFORMER_MERGE,
            ];
        }

        // база MySQL, кодировка базы отличается от UTF-8 (4 байта)
        if (
            isset($stat['character_set_database'])
            && 'utf8mb4' !== $stat['character_set_database']
        ) {
            $v->addError('Bad database charset');
        }

        // база PostgreSQL, кодировка базы
        if (
            isset($stat['server_encoding'])
            && 'UTF8' !== $stat['server_encoding']
        ) {
            $v->addError(['Bad database encoding', 'UTF8']);
        }

        // база PostgreSQL, порядок сопоставления/сортировки
        if (
            isset($stat['lc_collate'])
            && 'C' !== $stat['lc_collate']
        ) {
            $v->addError('Bad database collate');
        }

        // база PostgreSQL, тип символов
        if (
            isset($stat['lc_ctype'])
            && 'C' !== $stat['lc_ctype']
        ) {
            $v->addError('Bad database ctype');
        }

        // база SQLite, кодировка базы
        if (
            isset($stat['encoding'])
            && 'UTF-8' !== $stat['encoding']
        ) {
            $v->addError(['Bad database encoding', 'UTF-8']);
        }

        return $dbhost;
    }

    /**
     * Настраивает конфиг для создания DB
     */
    protected function createDBOptions(array $set): void
    {
        $this->c->DB_USERNAME    = $set['dbuser'];
        $this->c->DB_PASSWORD    = $set['dbpass'];
        $this->c->DB_OPTIONS     = [];
        $this->c->DB_OPTS_AS_STR = '';
        $this->c->DB_PREFIX      = $set['dbprefix'];

        // настройки подключения БД
        $DBEngine = 'MyISAM';

        switch ($set['dbtype']) {
            case 'mysql_innodb':
                $DBEngine = 'InnoDB';
            case 'mysql':
                if (\preg_match('%^([^:]+):(\d+)$%', $set['dbhost'], $matches)) {
                    $this->c->DB_DSN = "mysql:host={$matches[1]};port={$matches[2]};dbname={$set['dbname']};charset=utf8mb4";
                } else {
                    $this->c->DB_DSN = "mysql:host={$set['dbhost']};dbname={$set['dbname']};charset=utf8mb4";
                }

                break;
            case 'sqlite':
                $DBEngine                = '';
                $this->c->DB_DSN         = "sqlite:!PATH!{$set['dbname']}";
                $this->c->DB_OPTS_AS_STR = "\n"
                    . '        \\PDO::ATTR_TIMEOUT => 5,' . "\n"
                    . '        /* \'initSQLCommands\' => [\'PRAGMA journal_mode=WAL\',], */' . "\n"
                    . '        \'initFunction\' => function ($db) {return $db->sqliteCreateFunction(\'CONCAT\', function (...$args) {return \\implode(\'\', $args);});},' . "\n"
                    . '    ';
                $this->c->DB_OPTIONS     = [
                    PDO::ATTR_TIMEOUT => 5,
                    'initSQLCommands' => [
                        'PRAGMA journal_mode=WAL',
                    ],
                    'initFunction' => function ($db) {return $db->sqliteCreateFunction('CONCAT', function (...$args) {return \implode('', $args);});},
                ];

                break;
            case 'pgsql':
                $DBEngine = '';

                if (\preg_match('%^([^:]+):(\d+)$%', $set['dbhost'], $matches)) {
                    $host = $matches[1];
                    $port = $matches[2];
                } else {
                    $host = $set['dbhost'];
                    $port = '5432';
                }

                $this->c->DB_DSN = "pgsql:host={$host} port={$port} dbname={$set['dbname']} options='--client_encoding=UTF8'";

                break;
            default:
                $DBEngine = '';
                //????
                break;
        }

        $this->c->DBEngine = $DBEngine;
    }
}
