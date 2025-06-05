<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Controllers;

use ForkBB\Core\Container;
use ForkBB\Models\Page;

class Install
{
    public function __construct(protected Container $c)
    {
    }

    /**
     * Маршрутиризация
     */
    public function routing(): Page
    {
        $this->c->user = $this->c->users->create(['id' => 1, 'group_id' => FORK_GROUP_ADMIN]);

        $r = $this->c->Router;

        $r->add(
            $r::DUO,
            '/',
            'Install:start',
            'Start'
        );
        $r->add(
            $r::DUO,
            '/source/{key}',
            'Install:source',
            'Source'
        );
        $r->add(
            $r::DUO,
            '/receiver/{key}',
            'Install:receiver',
            'Receiver'
        );
        $r->add(
            $r::DUO,
            '/confirm/{key}',
            'Install:confirm',
            'Confirm'
        );
        $r->add(
            $r::DUO,
            '/step/{key}/{step|i:0|[1-9]\d*}/{id|i:0|[1-9]\d*}',
            'Install:step',
            'Step'
        );
        $r->add(
            $r::DUO,
            '/config/{key}',
            'Install:config',
            'Config'
        );
        $r->add(
            $r::GET,
            '/end/{key}',
            'Install:end',
            'End'
        );

        $uri = $_SERVER['REQUEST_URI'];

        if (false !== ($pos = \strpos($uri, '?'))) {
            $uri = \substr($uri, 0, $pos);
        }

        $uri    = \rawurldecode($uri);
        $method = $_SERVER['REQUEST_METHOD'];
        $route  = $r->route($method, $uri);
        $page   = null;

        switch ($route[0]) {
            case $r::OK:
                // ... 200 OK
                list($page, $action) = \explode(':', $route[1], 2);
                $page = $this->c->$page->$action($route[2], $method);

                break;
            default:
                $page = $this->c->Redirect->page('Start')->message('Redirect to install');

                break;
        }

        return $page;
    }
}
