<?php

declare(strict_types=1);

namespace HiCMS\Events\Auth;

use HiCMS\Events\Event;
use HiCMS\Model\User;

/**
 * Kullanıcı başarıyla giriş yaptığında tetiklenir.
 *
 * İki adımlı doğrulama gibi eklentiler burada araya girer.
 */
final class LoggedIn extends Event
{
    public function __construct(
        public readonly User $user,
        public readonly bool $remembered,
        public readonly string $ip,
    ) {
    }
}
