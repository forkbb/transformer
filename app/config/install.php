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

$extNotFound = \array_diff(
    [
        'date',
        'filter',
        'hash',
        'json',
        'SPL',
        'pcre',
        'PDO',
        'fileinfo',
        'intl',
        'mbstring',
    ],
    \get_loaded_extensions()
);

if (! empty($extNotFound)) {
    exit('Please enable the following extensions in PHP: ' . implode(', ', $extNotFound));
}

return [
    'BASE_URL'         => forkGetBaseURL(),
    'LIMIT'            => 1000,
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
    'DATE_FORMATS' => ['Y-m-d', 'd M Y', 'Y-m-d', 'Y-d-m', 'd-m-Y', 'm-d-Y', 'M j Y', 'jS M Y'],
    'TIME_FORMATS' => ['H:i:s', 'H:i', 'H:i:s', 'H:i', 'g:i:s a', 'g:i a'],
    'USERNAME' => [
        'phpPattern' => '%^\p{L}[\p{L}\p{N}\x20\._-]+$%uD',
        'jsPattern'  => '^.{2,}$',
        'min'        => 2,
        'max'        => 25,
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
        'o_avatars_dir'    => '/img/avatars',
    ],
    'FRIENDLY_URL' => [
        'lowercase' => true,
        'translit'  => true, // 'Any-Latin;Latin-ASCII;',
        'WtoHyphen' => true,
        'file'      => 'translit.default.php',
    ],

    'DRIVERS' => [
        'ForkBB'           => 'ForkBBDriver',
        'FluxBB_by_Visman' => 'FluxBB_by_VismanDriver',
        'FluxBB'           => 'FluxBBDriver',
        'PunBB'            => 'PunBBDriver',
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
        11 => 'topics_again',
        12 => 'forums_again',
        13 => 'warnings',
        14 => 'reports',
        15 => 'forum_subscriptions',
        16 => 'topic_subscriptions',
        17 => 'mark_of_forum',
        18 => 'mark_of_topic',
        19 => 'poll',
        20 => 'poll_voted',
        21 => 'pm_topics',
        22 => 'pm_posts',
        23 => 'pm_topics_again',
        24 => 'pm_block',
        25 => 'bans',
        26 => 'config',
        27 => 'providers',
        28 => 'providers_users',
        29 => 'attachments',
        30 => 'attachments_pos',
        31 => 'attachments_pos_pm',
        32 => 'reactions',
        33 => 'drafts',
//      'online',
//      'search_cache',
//      'search_matches',
//      'search_words',
        34 => 'other_again',
        35 => 'schema re-modification',
    ],

    'shared' => [
        '%DIR_ROOT%'   => \realpath(__DIR__ . '/../..'),
        '%DIR_PUBLIC%' => '%DIR_ROOT%/public',
        '%DIR_APP%'    => '%DIR_ROOT%/app',
        '%DIR_CACHE%'  => '%DIR_APP%/cache',
        '%DIR_CONFIG%' => '%DIR_APP%/config',
        '%DIR_LANG%'   => '%DIR_APP%/lang',
        '%DIR_LOG%'    => '%DIR_APP%/log',
        '%DIR_VIEWS%'  => '%DIR_APP%/templates',

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
            'class'  => \ForkBB\Core\View::class,
            'config' => [
                'cache'      => '%DIR_CACHE%',
                'defaultDir' => '%DIR_VIEWS%/_default',
                'userDir'    => '%DIR_VIEWS%/_user',
                'preFile'    => '%DIR_CONFIG%/ext/pre.php',
            ],
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
            'ssl'   => '%config.b_smtp_ssl%',
            'eol'   => '%EOL%',
            'file'  => '%DIR_CONFIG%/domains.default.php',
        ],
        'MailQueue' => [
            'class' => \ForkBB\Core\Mail\FileQueue::class,
            'path'  => '%DIR_CACHE%/mail',
        ],
        'Func'      => \ForkBB\Core\Func::class,
        'Test'      => [
            'class'  => \ForkBB\Core\Test::class,
            'config' => '%DIR_CONFIG%/test.default.php',
        ],
        'NormEmail' => \MioVisman\NormEmail\NormEmail::class,
        'Csrf' => [
            'class'   => \ForkBB\Core\Csrf::class,
            'Secury'  => '@Secury',
            'key'     => '%user.password%%user.ip%%user.id%%BASE_URL%',
            'extSalt' => '',
        ],
        'HTMLCleaner' => [
            'calss'  => \ForkBB\Core\HTMLCleaner::class,
            'config' => '%DIR_CONFIG%/jevix.default.php',
        ],
        'Transformer' => \ForkBB\Models\Transformer\Transformer::class,
        'Log'       => [
            'class'  => \ForkBB\Core\Log::class,
            'config' => [
                'path'       => '%DIR_LOG%/{Y-m-d}.log',
                'lineFormat' => "\\%datetime\\% [\\%level_name\\%] \\%message\\%\t\\%context\\%\n",
                'timeFormat' => 'Y-m-d H:i:s',
            ],
        ],

        'config'     => '@ConfigModel:install',
        'users'      => \ForkBB\Models\User\Users::class,
        'userRules'  => '@UsersRules:init',

        'UsersRules' => \ForkBB\Models\Rules\Users::class,

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

        'ForkBBDriver'           => \ForkBB\Models\Transformer\Driver\ForkBB\ForkBB::class,
        'FluxBB_by_VismanDriver' => \ForkBB\Models\Transformer\Driver\FluxBB_by_Visman\FluxBB_by_Visman::class,
        'FluxBBDriver'           => \ForkBB\Models\Transformer\Driver\FluxBB\FluxBB::class,
        'PunBBDriver'            => \ForkBB\Models\Transformer\Driver\PunBB\PunBB::class,
    ],
];
