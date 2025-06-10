<?php
/**
 * This file is part of the ForkBB <https://forkbb.ru, https://github.com/forkbb>.
 *
 * @copyright (c) Visman <mio.visman@yandex.ru, https://github.com/MioVisman>
 * @license   The MIT License (MIT)
 */

declare(strict_types=1);

namespace ForkBB\Models\User;

use ForkBB\Core\Container;
use ForkBB\Models\DataModel;
use ForkBB\Models\Model;
use ForkBB\Models\Forum\Forum;
use ForkBB\Models\Post\Post;
use RuntimeException;
use function \ForkBB\__;

class User extends DataModel
{
    /**
     * Ключ модели для контейнера
     */
    protected string $cKey = 'User';

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->zDepend = [
            'group_id'      => ['isUnverified', 'isGuest', 'isAdmin', 'isAdmMod', 'isBanByName', 'link', 'usePM', 'linkEmail'],
            'id'            => ['isGuest', 'link', 'online', 'linkEmail'],
            'last_visit'    => ['currentVisit'],
            'signature'     => ['isSignature'],
            'email'         => ['email_normal', 'linkEmail'],
            'username'      => ['username_normal'],
            'g_pm'          => ['usePM'],
            'email_setting' => ['linkEmail'],
        ];
    }

    /**
     * Статус неподтвержденного
     */
    protected function getisUnverified(): bool
    {
        return FORK_GROUP_UNVERIFIED === $this->group_id;
    }

    /**
     * Статус гостя
     */
    protected function getisGuest(): bool
    {
        return FORK_GROUP_GUEST === $this->group_id
            || null === $this->group_id
            || $this->id < 1;
    }

    /**
     * Статус админа
     */
    protected function getisAdmin(): bool
    {
        return FORK_GROUP_ADMIN === $this->group_id;
    }

    /**
     * Статус админа/модератора
     */
    protected function getisAdmMod(): bool
    {
        return $this->isAdmin
            || 1 === $this->g_moderator;
    }

    /**
     * Статус бана по имени пользователя
     */
    protected function getisBanByName(): bool
    {
        return ! $this->isAdmin
            && $this->c->bans->banFromName($this->username) > 0;
    }

    /**
     * Статус модератора для указанной модели
     */
    public function isModerator(Model $model): bool
    {
        if (1 !== $this->g_moderator) {
            return false;
        }

        while (! $model instanceof Forum) {
            $model = $model->parent;

            if (! $model instanceof Model) {
                throw new RuntimeException('Moderator\'s rights can not be found');
            }
        }

        return isset($model->moderators[$this->id]);
    }

    /**
     * Время последнего (или текущего) визита
     */
    protected function getcurrentVisit(): int
    {
        return $this->c->Online->currentVisit($this) ?? $this->last_visit;
    }

    /**
     * Текущий язык пользователя
     */
    protected function getlanguage(): string
    {
        $langs = $this->c->Func->getLangs();
        $lang  = $this->getModelAttr('language');

        if (
            empty($lang)
            || ! isset($langs[$lang])
        ) {
            $lang = $this->c->config->o_default_lang;
        }

        if (isset($langs[$lang])) {
            return $lang;

        } else {
            return \reset($langs) ?: 'en';
        }
    }

    /**
     * Текущий стиль отображения
     */
    protected function getstyle(): string
    {
        $styles = $this->c->Func->getStyles();
        $style  = $this->getModelAttr('style');

        if (
            $this->isGuest
            || empty($style)
            || ! isset($styles[$style])
        ) {
            $style = $this->c->config->o_default_style;
        }

        if (isset($styles[$style])) {
            return $style;

        } else {
            return \reset($styles) ?: 'ForkBB';
        }
    }

    /**
     * Ссылка на профиль пользователя
     */
    protected function getlink(): ?string
    {
        if ($this->isGuest) {
            return null;

        } else {
            return $this->c->Router->link(
                'User',
                [
                    'id'   => $this->id,
                    'name' => $this->c->Func->friendly($this->username),
                ]
            );
        }
    }

    /**
     * Ссылка на аватару пользователя
     */
    protected function getavatar(): ?string
    {
        $file = $this->getModelAttr('avatar');

        if (! empty($file)) {
            $file = $this->c->config->o_avatars_dir . '/' . $file;
            $path = $this->c->DIR_PUBLIC . $file;

            if (\is_file($path)) {
                return $this->c->PUBLIC_URL . $file;
            }
        }

        return null;
    }

    /**
     * Удаляет аватару пользователя
     */
    public function deleteAvatar(): void
    {
        $file = $this->getModelAttr('avatar');

        if (! empty($file)) {
            $path = $this->c->DIR_PUBLIC . "{$this->c->config->o_avatars_dir}/{$file}";

            if (\is_file($path)) {
                \unlink($path);
            }

            $this->avatar = '';
        }
    }

    /**
     * Титул пользователя
     */
    public function title(): string
    {
        if ($this->isGuest) {
            return __('Guest');

        } elseif ($this->isBanByName) {
            return __('Banned');

        } elseif ('' != $this->title) {
            return $this->censorTitle;

        } elseif ('' != $this->g_user_title) {
            return $this->censorG_user_title;

        } elseif ($this->isUnverified) {
            return __('Unverified');

        } else {
            return __('Member');
        }
    }

    /**
     * Статус online
     */
    protected function getonline(): bool
    {
        return $this->c->Online->isOnline($this);
    }

    /**
     * Статус наличия подписи
     */
    protected function getisSignature(): bool
    {
        return $this->g_sig_length > 0
            && $this->g_sig_lines > 0
            && '' != $this->signature;
    }

    /**
     * HTML код подписи
     */
    protected function gethtmlSign(): string
    {
        return $this->isSignature
            ? $this->c->censorship->censor($this->c->Parser->parseSignature($this->signature))
            : '';
    }

    /**
     * Число тем на одну страницу
     */
    protected function getdisp_topics(): int
    {
        $attr = $this->getModelAttr('disp_topics');

        if ($attr < 10) {
            $attr = $this->c->config->i_disp_topics_default;
        }

        return $attr;
    }

    /**
     * Число сообщений на одну страницу
     */
    protected function getdisp_posts(): int
    {
        $attr = $this->getModelAttr('disp_posts');

        if ($attr < 10) {
            $attr = $this->c->config->i_disp_posts_default;
        }

        return $attr;
    }

    /**
     * Ссылка для продвижения пользователя из указанного сообщения
     */
    public function linkPromote(Post $post): ?string
    {
        if (
            (
                $this->isAdmin
                || (
                    $this->isAdmMod
                    && 1 === $this->g_mod_promote_users
                )
            )
            && $this->id !== $post->user->id //????
            && 0 < $post->user->g_promote_min_posts * $post->user->g_promote_next_group
            && ! $post->user->isBanByName
        ) {
            return $this->c->Router->link(
                'AdminUserPromote',
                [
                    'uid' => $post->user->id,
                    'pid' => $post->id,
                ]
            );

        } else {
            return null;
        }
    }

    /**
     * Вычисляет нормализованный email
     */
    protected function getemail_normal(): string
    {
        return $this->c->NormEmail->normalize($this->email);
    }

    /**
     * Вычисляет нормализованный username
     */
    protected function getusername_normal(): string
    {
        return $this->c->users->normUsername($this->username);
    }

    /**
     * Возвращает значения свойств в массиве
     */
    public function getModelAttrs(): array
    {
        foreach (['email_normal', 'username_normal'] as $key) {
            if (isset($this->zModFlags[$key])) {
                $this->setModelAttr($key, $this->$key);
            }
        }

        return parent::getModelAttrs();
    }

    /**
     * Информация для лога
     */
    public function fLog(): string
    {
        $name = $this->isGuest ? ($this->isBot ? 'bot' : 'guest') : "name:{$this->username}";

        return "id:{$this->id} gid:{$this->group_id} {$name}";
    }

    /**
     * Статус возможности использования приватных сообщений
     */
    protected function getusePM(): bool
    {
        return 1 === $this->c->config->b_pm
            && (
                1 === $this->g_pm
                || $this->isAdmin
            );
    }

    /**
     * Формирует ссылку на отправку письма от $this->c->user к $this
     * ???? Результат используется как условие для вариантов отображения
     */
    protected function getlinkEmail(): ?string
    {
        if (
            $this->c->user->isGuest
            || $this->id === $this->c->user->id
            || empty($this->email)
        ) {
            return '';

        } elseif (2 === $this->email_setting) {
            return null;

        } elseif (
            0 === $this->email_setting
            || (
                $this->isGuest
                && $this->c->user->isAdmMod
            )
        ) {
            return 'mailto:' . $this->censorEmail;

        } elseif (
            1 === $this->email_setting
            && (
                1 === $this->c->user->g_send_email
                || $this->c->user->isAdmin
            )
        ) {
            $this->c->Csrf->setHashExpiration(3600);

            return $this->c->Router->link('SendEmail', ['id' => $this->id]);

        } else {
            return '';
        }
    }
}
