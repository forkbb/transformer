<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */
/**
 * based on Dirk <https://github.com/artoodetoo/dirk>
 *
 * @copyright (c) 2015 artoodetoo <i.am@artoodetoo.org, https://github.com/artoodetoo>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Core;

use ForkBB\Core\View\Compiler;
use ForkBB\Models\Page;
use RuntimeException;
use function \ForkBB\e;

class View
{
    protected string $ext = '.forkbb.php';
    protected string $preFile = '';

    protected ?Compiler $compilerObj;
    protected string    $compilerClass = Compiler::class;

    protected string $cacheDir;
    protected string $defaultDir;
    protected string $defaultHash;

    protected array $other      = [];
    protected array $composers  = [];
    protected array $blocks     = [];
    protected array $blockStack = [];
    protected array $templates  = [];

    public function __construct(string|array $config, mixed $views)
    {
        if (\is_array($config)) {
            $this->cacheDir   = $config['cache'];
            $this->defaultDir = $config['defaultDir'];

            if (! empty($config['userDir'])) {
                $this->setUserDir($config['userDir']);
            }

            if (! empty($config['composers'])) {
                foreach ($config['composers'] as $name => $composer) {
                    $this->composer($name, $composer);
                }
            }

            if (! empty($config['compiler'])) {
                $this->compilerClass = $config['compiler'];
            }

            if (! empty($config['preFile'])) {
                $this->preFile = $config['preFile'];
            }

        } else {
            // для rev. 68 и ниже
            $this->cacheDir   = $config;
            $this->defaultDir = $views;
        }

        $this->defaultHash = \hash('md5', $this->defaultDir);
    }

    /**
     * Ищет первую существующую директорию из $data (разделитель "|")
     * и добавляет её в каталог шаблонов с приоритетом = 10
     */
    protected function setUserDir(string $data): void
    {
        foreach (\explode('|', $data) as $dir) {
            if (\is_dir($dir)) {
                $this->addTplDir($dir, 10);

                return;
            }
        }
    }

    /**
     * Добавляет новый каталог шаблонов $pathToDir.
     * Сортирует список каталогов в соответствии с приоритетом $priority. По убыванию.
     */
    public function addTplDir(string $pathToDir, int $priority): View
    {
        $this->other[\hash('md5', $pathToDir)] = [$pathToDir, $priority];

        if (\count($this->other) > 1) {
            \uasort($this->other, function (array $a, array $b) {
                return $b[1] <=> $a[1];
            });
        }

        return $this;
    }

    /**
     * Возвращает отображение страницы $p или null
     */
    public function rendering(Page $p, bool $sendHeaders = true): ?string
    {
        if (null === $p->nameTpl) {
            $this->sendHttpHeaders($p);

            return null;
        }

//        $p->prepare();

        $this->templates[] = $p->nameTpl;

        while ($_name = \array_shift($this->templates)) {
            $this->beginBlock('content');

            foreach ($this->composers as $_cname => $_cdata) {
                if (\preg_match($_cname, $_name)) {
                    foreach ($_cdata as $_citem) {
                        \extract((\is_callable($_citem) ? $_citem($this) : $_citem) ?: []);
                    }
                }
            }

            require $this->prepare($_name);

            $this->endBlock(true);
        }

        if (true === $sendHeaders) {
            $this->sendHttpHeaders($p);
        }

        return $this->block('content');
    }

    /**
     * Отправляет HTTP заголовки
     */
    protected function sendHttpHeaders(Page $p): void
    {
        foreach ($p->httpHeaders as $catHeader) {
            foreach ($catHeader as $header) {
                \header($header[0], $header[1]);
            }
        }
    }

    /**
     * Возвращает отображение шаблона $name
     */
    public function fetch(string $name, array $data = []): string
    {
        $this->templates[] = $name;

        if (! empty($data)) {
            \extract($data);
        }

        while ($_name = \array_shift($this->templates)) {
            $this->beginBlock('content');

            foreach ($this->composers as $_cname => $_cdata) {
                if (\preg_match($_cname, $_name)) {
                    foreach ($_cdata as $_citem) {
                        \extract((\is_callable($_citem) ? $_citem($this) : $_citem) ?: []);
                    }
                }
            }

            require $this->prepare($_name);

            $this->endBlock(true);
        }

        return $this->block('content');
    }

    /**
     * Add view composer
     * @param mixed $name     template name or array of names
     * @param mixed $composer data in the same meaning as for fetch() call, or callable returning such data
     */
    public function composer(string|array $name, mixed $composer): void
    {
        if (\is_array($name)) {
            foreach ($name as $n) {
                $this->composer($n, $composer);
            }

        } else {
            $p = '~^'
                . \str_replace('\*', '[^' . $this->separator . ']+', \preg_quote($name, $this->separator . '~'))
                . '$~';
            $this->composers[$p][] = $composer;
        }
    }

    /**
     * Подготавливает файл для подключения
     */
    protected function prepare(string $name): string
    {
        $st = \preg_replace('%\W%', '-', $name);

        foreach ($this->other as $hash => $cur) {
            if (\file_exists($tpl = "{$cur[0]}/{$name}{$this->ext}")) {
                $php = "{$this->cacheDir}/_{$st}-{$hash}.php";

                if (
                    ! \file_exists($php)
                    || \filemtime($tpl) > \filemtime($php)
                ) {
                    $this->create($php, $tpl, $name);
                }

                return $php;
            }
        }

        $hash = $this->defaultHash;
        $tpl  = "{$this->defaultDir}/{$name}{$this->ext}";
        $php  = "{$this->cacheDir}/_{$st}-{$hash}.php";

        if (
            ! \file_exists($php)
            || \filemtime($tpl) > \filemtime($php)
        ) {
            $this->create($php, $tpl, $name);
        }

        return $php;
    }

    /**
     * Удаляет файлы кэша для шаблона $name
     */
    public function delete(string $name): void
    {
        $st = \preg_replace('%\W%', '-', $name);

        \array_map('\\unlink', \glob("{$this->cacheDir}/_{$st}-*.php"));
    }

    /**
     * Генерирует $php файл на основе шаблона $tpl
     */
    protected function create(string $php, string $tpl, string $name): void
    {
        if (empty($this->compilerObj)) {
            $this->compilerObj = new $this->compilerClass($this->preFile);
        }

        $text = $this->compilerObj->create($name, \file_get_contents($tpl), \hash('fnv1a32', $tpl));

        if (false === \file_put_contents($php, $text, \LOCK_EX)) {
            throw new RuntimeException("Failed to write {$php} file");
        }

        if (\function_exists('\\opcache_invalidate')) {
            \opcache_invalidate($php, true);
        }
    }

    /**
     * Задает родительский шаблон
     */
    protected function extend(string $name): void
    {
        $this->templates[] = $name;
    }

    /**
     * Возвращает содержимое блока или $default
     */
    protected function block(string $name, string $default = ''): string
    {
        return \array_key_exists($name, $this->blocks)
            ? $this->blocks[$name]
            : $default;
    }

    /**
     * Задает начало блока
     */
    protected function beginBlock(string $name): void
    {
        $this->blockStack[] = $name;

        \ob_start();
    }

    /**
     * Задает конец блока
     */
    protected function endBlock(bool $overwrite = false): string
    {
        $name = \array_pop($this->blockStack);

        if (
            $overwrite
            || ! \array_key_exists($name, $this->blocks)
        ) {
            $this->blocks[$name] = \ob_get_clean();

        } else {
            $this->blocks[$name] .= \ob_get_clean();
        }

        return $name;
    }

    /**
     * Формирует строку class="..."
     */
    protected function createClass(mixed $data): string
    {
        if (! \is_array($data)) {
            return '';
        }

        $out = [];

        foreach ($data as $key => $value) {
            if (\is_string($key)) {
                if (! empty($value)) {
                    $out[] = $key;
                }

            } elseif (\is_string($value)) {
                $out[] = $value;

            } elseif (
                \is_array($value)
                && ! empty($value[0])
            ) {
                $value[1] ??= '';
                $value[2] ??= '';
                $out[]      = $value[1] . (\is_array($value[0]) ? \implode("{$value[2]} {$value[1]}", $value[0]) : $value[0]) . $value[2];
            }
        }

        return empty($out) ? '' : ' class="' . e(\implode(' ', $out)) . '"';
    }
}
