<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Core;

use ForkBB\Core\Files;
use ForkBB\Core\File;
use ForkBB\Core\Image\DefaultDriver;
use ForkBB\Core\Exceptions\FileException;
use InvalidArgumentException;

class Image extends File
{
    /**
     * Изображение
     */
    protected mixed $image;

    /**
     * Класс обработки изображений
     */
    protected DefaultDriver $imgDriver;

    /**
     * Качество изображения
     */
    protected int $quality = 100;

    /**
     * Паттерн для pathinfo
     */
    protected string $pattern = '%^(?!.*?\.\.)([\w.\x5C/:-]*[\x5C/])?(\*|[\w.-]+)\.(\*|[a-z\d]+|\([a-z\d]+(?:\|[a-z\d]+)*\))$%iD';

    public function __construct(string $path, string $name, string $ext, Files $files)
    {
        parent::__construct($path, $name, $ext, $files);

        $this->imgDriver = $files->imageDriver();

        if ($this->imgDriver::DEFAULT) {
            throw new FileException('No library for work with images');
        }

        if (\is_string($this->data)) {
            $this->image = $this->imgDriver->readFromStr($this->data);

        } else {
            $this->image = $this->imgDriver->readFromPath($this->path);
        }

        if (false === $this->image) {
            throw new FileException('Invalid image data');
        }
    }

    /**
     * Изменяет размер изображения при необходимости
     */
    public function resize(int $maxW, int $maxH): Image
    {
        $this->image = $this->imgDriver->resize($this->image, $maxW, $maxH);

        return $this;
    }

    /**
     * Проверяет и устанавливает качество для сохраняемого изображения
     */
    public function setQuality(int $quality): Image
    {
        if (
            $quality < 0
            || $quality > 100
        ) {
            throw new InvalidArgumentException('Invalid image quality value: ' . $quality);
        }

        $this->quality = $quality;

        return $this;
    }

    /**
     * Возвращает информацию о пути к сохраняемой картинке с учетом подстановок
     */
    protected function pathinfo(string $path): ?array
    {
        $info = parent::pathinfo($path);

        if (null === $info) {
            return null;
        }

        if (\is_array($info['extension'])) {
            if (\in_array($this->ext, $info['extension'], true)) {
                $info['extension'] = $this->ext;

            } else {
                $info['extension'] = \reset($info['extension']); // ???? выбор расширения?
            }
        }

        return $info;
    }

    /**
     * Создает/устанавливает права на картинку
     */
    protected function fileProc(string $path): bool
    {
        $result = $this->imgDriver->writeToPath($this->image, $path, $this->quality);

        if (null === $result) {
            $result      = false;
            $this->error = 'File type not supported';

        } elseif (! $result) {
            $this->error = 'Error writing file';

        } else {
            \chmod($path, 0644);
        }

        return $result;
    }

    public function width(): int
    {
        return $this->imgDriver->width($this->image);
    }

    public function height(): int
    {
        return $this->imgDriver->height($this->image);
    }

    public function __destruct()
    {
        $this->imgDriver->destroy($this->image);
    }
}
