<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\I18n;
use Tests\Support\TestCase;

/** PRD §12 / UAT-23: dua bahasa, kunci lengkap, format tanggal per bahasa. */
final class I18nTest extends TestCase
{
    public function testBothCatalogsHaveIdenticalKeys(): void
    {
        $id = array_keys(I18n::catalog('id'));
        $en = array_keys(I18n::catalog('en'));
        sort($id);
        sort($en);
        $this->assertSame([], array_values(array_diff($id, $en)), 'Kunci ada di id.php tetapi tidak di en.php');
        $this->assertSame([], array_values(array_diff($en, $id)), 'Kunci ada di en.php tetapi tidak di id.php');
    }

    public function testNoEmptyTranslations(): void
    {
        foreach (['id', 'en'] as $loc) {
            foreach (I18n::catalog($loc) as $key => $text) {
                $this->assertNotSame('', trim((string) $text), "{$loc}:{$key} kosong");
            }
        }
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $en = I18n::catalog('en');
        foreach (I18n::catalog('id') as $key => $text) {
            preg_match_all('/:([a-z_]+)/', $text, $a);
            preg_match_all('/:([a-z_]+)/', $en[$key], $b);
            sort($a[1]);
            sort($b[1]);
            $this->assertSame($a[1], $b[1], "Placeholder berbeda pada {$key}");
        }
    }

    public function testDefaultLocaleIsIndonesian(): void
    {
        $this->assertSame('id', I18n::locale());
        $this->assertSame('Masuk', I18n::t('auth.submit'));
    }

    public function testSwitchLanguage(): void
    {
        I18n::setLocale('en');
        $this->assertSame('Sign in', I18n::t('auth.submit'));
        I18n::setLocale('xx'); // tidak didukung → kembali ke id
        $this->assertSame('id', I18n::locale());
    }

    public function testParameterReplacement(): void
    {
        $this->assertSame('Halo, Rina', I18n::t('dashboard.greeting', ['name' => 'Rina']));
    }

    public function testDateFormatPerLanguage(): void
    {
        $this->assertSame('05 Okt 2026', I18n::date('2026-10-05', 'id'));
        $this->assertSame('05 Oct 2026', I18n::date('2026-10-05', 'en'));
        $this->assertSame('17 Agu 2026', I18n::date('2026-08-17', 'id'));
        $this->assertSame('–', I18n::date(null));
    }

    public function testNumberFormatPerLanguage(): void
    {
        $this->assertSame('1.234,50', I18n::number(1234.5, 2, 'id'));
        $this->assertSame('1,234.50', I18n::number(1234.5, 2, 'en'));
    }

    public function testEveryRoleAndPermissionHasTranslation(): void
    {
        $root = dirname(__DIR__, 2) . '/database/seeds';
        foreach (require $root . '/roles.php' as $role) {
            $this->assertTrue(I18n::has('role.' . $role['code'], 'id'));
            $this->assertTrue(I18n::has('role.' . $role['code'], 'en'));
        }
        foreach (array_keys(require $root . '/permissions.php') as $code) {
            $this->assertTrue(I18n::has('perm.' . $code, 'en'), 'perm.' . $code);
        }
    }

    public function testUnknownKeyReturnsKey(): void
    {
        $this->assertSame('tidak.ada', I18n::t('tidak.ada'));
    }
}
