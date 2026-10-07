<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\NumberSequence;
use App\Core\Request;
use Tests\Support\TestCase;

final class CoreHelpersTest extends TestCase
{
    public function testEscapeHelperNeutralisesHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;', e("<script>alert('x')</script>"));
        $this->assertSame('&quot;onmouseover=&quot;x', e('"onmouseover="x'));
        $this->assertSame('', e(null));
    }

    public function testJsonAttrIsSafeInsideAttributes(): void
    {
        $out = json_attr(['a' => '</script><img src=x onerror=1>"']);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('"', $out);
        $this->assertStringNotContainsString("'", $out);
    }

    public function testRomanMonths(): void
    {
        $this->assertSame('I', NumberSequence::romanMonth(1));
        $this->assertSame('IV', NumberSequence::romanMonth(4));
        $this->assertSame('IX', NumberSequence::romanMonth(9));
        $this->assertSame('X', NumberSequence::romanMonth(10));
        $this->assertSame('XII', NumberSequence::romanMonth(12));
        $this->expectException(\InvalidArgumentException::class);
        NumberSequence::romanMonth(13);
    }

    public function testSqlIdentifierWhitelist(): void
    {
        Db::assertIdentifier('users');
        Db::assertIdentifier('password_hash');
        $this->expectException(\InvalidArgumentException::class);
        Db::assertIdentifier('users; DROP TABLE users');
    }

    public function testSqlIdentifierRejectsBackticks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Db::assertIdentifier('name`');
    }

    public function testSafeReturnPathRejectsOpenRedirects(): void
    {
        $this->assertSame('/projects.php?x=1', Request::safeReturnPath('/projects.php?x=1', '/d'));
        $this->assertSame('/d', Request::safeReturnPath('//evil.example/x', '/d'));
        $this->assertSame('/d', Request::safeReturnPath('https://evil.example', '/d'));
        $this->assertSame('/d', Request::safeReturnPath("/ok\r\nLocation: x", '/d'));
        $this->assertSame('/d', Request::safeReturnPath('/\\evil', '/d'));
        $this->assertSame('/d', Request::safeReturnPath(null, '/d'));
    }

    public function testCryptoRoundTripAndTamperDetection(): void
    {
        $enc = Crypto::encrypt('rahasia-smtp');
        $this->assertStringStartsWith('v1:', $enc);
        $this->assertNotSame(Crypto::encrypt('rahasia-smtp'), $enc, 'nonce acak');
        $this->assertSame('rahasia-smtp', Crypto::decrypt($enc));
        $tampered = substr($enc, 0, -2) . (substr($enc, -2) === 'AA' ? 'BB' : 'AA');
        $this->assertNull(Crypto::decrypt($tampered));
    }
}
