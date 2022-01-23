<?php
/**
 * This file is part of the ForkBB <https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

\error_reporting(\E_ALL);
\ini_set('display_errors', '1');
\ini_set('log_errors', '1');

define('FORK_GROUP_NEW_MEMBER', 5);

function forkGetBaseURL()
{
    $file    = \str_replace(\realpath($_SERVER['DOCUMENT_ROOT']), '', \realpath($_SERVER['SCRIPT_FILENAME']));
    $baseURL = 'http://'
        . \preg_replace('%:(80|443)$%', '', $_SERVER['HTTP_HOST'])
        . \str_replace('\\', '/', \dirname($file)); // $_SERVER['SCRIPT_NAME']

    return \rtrim($baseURL, '/');
}

return [
    'BASE_URL'         => forkGetBaseURL(),
    'LIMIT'            => 100,
    'DEBUG'            => 2,
    'EOL'              => \PHP_EOL,
    'MAX_EMAIL_LENGTH' => 80,
    'FLOOD_INTERVAL'   => 3600,
    'HTTP_HEADERS'     => [
        'common' => [],
        'secure' => [],
    ],

    'HMAC' => [
        'algo' => 'sha1',
        'salt' => '_SALT_FOR_HMAC_',
    ],

    'forConfig' => [
        'o_board_title'    => 'Transformer',
        'o_default_lang'   => 'en',
        'o_default_style'  => 'ForkBB',
        'i_redirect_delay' => 1,
        'b_maintenance'    => 0,
        'o_smtp_host'      => '',
        'o_smtp_user'      => '',
        'o_smtp_pass'      => '',
        'b_smtp_ssl'       => 0,
    ],

    'DRIVERS' => [
        'ForkBB' => 'ForkBBDriver',
    ],

    'STEPS' => [
        0  => 'schema setup',
        1  => 'categories',
        2  => 'groups',
        3  => 'users',
        4  => 'forums',
        5  => 'forum_perms',
        6  => 'bbcode',
        7  => 'censoring',
        8  => 'smilies',
        9  => 'topics',
        10 => 'posts',
        11 => 'warnings',
        12 => 'reports',
        13 => 'forum_subscriptions',
        14 => 'topic_subscriptions',
        15 => 'mark_of_forum',
        16 => 'mark_of_topic',
        17 => 'poll',
        18 => 'poll_voted',
        19 => 'pm_topics',
        20 => 'pm_posts',
        21 => 'pm_block',
        22 => 'bans',
        23 => 'config',
//      'online',
//      'search_cache',
//      'search_matches',
//      'search_words',
        24 => 'schema re-modification',
    ],

    'shared' => [
        'DB' => [
            'class'    => \ForkBB\Core\DB::class,
            'dsn'      => '%DB_DSN%',
            'username' => '%DB_USERNAME%',
            'password' => '%DB_PASSWORD%',
            'options'  => '%DB_OPTIONS%',
            'prefix'   => '%DB_PREFIX%',
        ],
        'DBSource' => [
            'class'    => \ForkBB\Core\DB::class,
            'dsn'      => '%DB_DSN%',
            'username' => '%DB_USERNAME%',
            'password' => '%DB_PASSWORD%',
            'options'  => '%DB_OPTIONS%',
            'prefix'   => '%DB_PREFIX%',
        ],
        'Secury' => [
            'class' => \ForkBB\Core\Secury::class,
            'hmac'  => '%HMAC%',
        ],
        'Cache' => [
            'class'      => \ForkBB\Core\Cache\FileCache::class,
            'cache_dir'  => '%DIR_CACHE%',
            'reset_mark' => '',
        ],
        'Validator' => \ForkBB\Core\Validator::class,
        'View' => [
            'class'     => \ForkBB\Core\View::class,
            'cache_dir' => '%DIR_CACHE%',
            'views_dir' => '%DIR_VIEWS%',
        ],
        'Router' => [
            'class'    => \ForkBB\Core\Router::class,
            'base_url' => '%BASE_URL%',
            'csrf'     => '@Csrf'
        ],
        'Lang' => \ForkBB\Core\Lang::class,
        'Mail' => [
            'class' => \ForkBB\Core\Mail::class,
            'host'  => '%config.o_smtp_host%',
            'user'  => '%config.o_smtp_user%',
            'pass'  => '%config.o_smtp_pass%',
            'ssl'   => '%config.o_smtp_ssl%',
            'eol'   => '%EOL%',
        ],
        'Func' => \ForkBB\Core\Func::class,
        'NormEmail' => \MioVisman\NormEmail\NormEmail::class,
        'Csrf' => [
            'class'  => \ForkBB\Core\Csrf::class,
            'Secury' => '@Secury',
            'key'    => '%user.password%%user.ip%%user.id%%BASE_URL%',
        ],
        'HTMLCleaner' => [
            'calss'  => \ForkBB\Core\HTMLCleaner::class,
            'config' => '%DIR_APP%/config/jevix.default.php',
        ],
        'Transformer' => \ForkBB\Models\Transformer\Transformer::class,

        'config'     => '@ConfigModel:install',
        'users'      => \ForkBB\Models\User\Users::class,

        'VLemail'    => \ForkBB\Models\Validators\Email::class,
        'VLhtml'     => \ForkBB\Models\Validators\Html::class,

        'Users/normUsername' => \ForkBB\Models\User\NormUsername::class,
    ],
    'multiple'  => [
        'PrimaryController' => \ForkBB\Controllers\Install::class,
        'Primary' => '@PrimaryController:routing',

        'Debug'    => \ForkBB\Models\Pages\Debug::class,
        'Install'  => \ForkBB\Models\Pages\Admin\Install::class,
        'Redirect' => \ForkBB\Models\Pages\Redirect::class,

        'UserModel'  => \ForkBB\Models\User\User::class,

        'ConfigModel'    => \ForkBB\Models\Config\Config::class,
        'Config/install' => \ForkBB\Models\Config\Install::class,

        'ForkBBDriver' => \ForkBB\Models\Transformer\Driver\ForkBB\ForkBB::class,
    ],
];
