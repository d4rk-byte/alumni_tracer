<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserRoleMappingTest extends TestCase
{
    public function testAdminRoleIsStoredAsNumericCode(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN']);

        self::assertSame([User::ROLE_CODE_ADMIN], $user->getRoleCodes());
        self::assertSame(User::ROLE_CODE_ADMIN, $user->getPrimaryRoleCode());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertContains('ROLE_USER', $user->getRoles());
    }

    public function testAlumniRoleMapsToUserCodeAndKeepsLegacyAlias(): void
    {
        $user = (new User())->setRoles([User::ROLE_ALUMNI]);

        self::assertSame([User::ROLE_CODE_USER], $user->getRoleCodes());
        self::assertContains('ROLE_USER', $user->getRoles());
        self::assertContains(User::ROLE_ALUMNI, $user->getRoles());
    }

    public function testEmptyRoleSetDefaultsToUserCode(): void
    {
        $user = (new User())->setRoles([]);

        self::assertSame([User::ROLE_CODE_USER], $user->getRoleCodes());
        self::assertSame(User::ROLE_CODE_USER, $user->getPrimaryRoleCode());
    }

    public function testRoleStoragePatternsCoverLegacyAndNumericFormats(): void
    {
        self::assertContains('%"ROLE_STAFF"%', User::getRoleStoragePatterns('ROLE_STAFF'));
        self::assertContains('[2]', User::getRoleStoragePatterns('ROLE_STAFF'));
        self::assertContains('%"ROLE_ALUMNI"%', User::getRoleStoragePatterns('ROLE_USER'));
        self::assertContains('%,3]', User::getRoleStoragePatterns(User::ROLE_CODE_USER));
    }
}