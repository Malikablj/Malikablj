<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Response;
use PHPUnit\Framework\TestCase;

/** Awalan URL untuk berbagai cara deploy, termasuk folder aplikasi sebagai document root (cPanel + .htaccess akar). */
final class BasePathTest extends TestCase
{
    private function base(string $scriptName, string $file, string $requestUri): string
    {
        $public = (string) realpath(dirname(__DIR__, 2) . '/public');
        return Response::detectBasePath(['SCRIPT_NAME' => $scriptName, 'SCRIPT_FILENAME' => $public . $file, 'REQUEST_URI' => $requestUri], $public);
    }

    public function testCommonDeployments(): void
    {
        // document root = public/ (php -S, nginx, Apache vhost)
        $this->assertSame('', $this->base('/login.php', '/login.php', '/login.php?next=1'));
        $this->assertSame('', $this->base('/settings/users.php', '/settings/users.php', '/settings/users.php'));
        // subfolder tanpa rewrite (XAMPP: http://localhost/npd/public/)
        $this->assertSame('/npd/public', $this->base('/npd/public/settings/users.php', '/settings/users.php', '/npd/public/settings/users.php'));
        // cPanel: folder domain = folder aplikasi, .htaccess akar → public/
        $this->assertSame('', $this->base('/public/login.php', '/login.php', '/login.php'));
        $this->assertSame('', $this->base('/public/settings/users.php', '/settings/users.php', '/settings/users.php?q=a'));
        $this->assertSame('', $this->base('/public/index.php', '/index.php', '/'));
        // aplikasi di subfolder domain dengan .htaccess akar (https://domain/npd/login.php)
        $this->assertSame('/npd', $this->base('/npd/public/login.php', '/login.php', '/npd/login.php'));
        $this->assertSame('/npd', $this->base('/npd/public/index.php', '/index.php', '/npd/'));
    }

    public function testClientControlledUriCannotInjectForeignOrTraversalBase(): void
    {
        // URL buatan penyerang tidak boleh menjadi awalan tautan/redirect (//evil.com → open redirect)
        foreach (['//evil.com/login.php', '/%2F%2Fevil.com/login.php', '/x/../login.php', '/a b/login.php', '/<script>/login.php', '/./login.php'] as $uri) {
            $base = $this->base('/public/login.php', '/login.php', $uri);
            $this->assertContains($base, ['', '/public'], $uri);
            $this->assertDoesNotMatchRegularExpression('#evil|\.\.|//|<| #', $base, $uri);
        }
        // file di luar public/ tidak menghasilkan awalan
        $this->assertSame('', Response::detectBasePath(['SCRIPT_NAME' => '/x.php', 'SCRIPT_FILENAME' => __FILE__, 'REQUEST_URI' => '/x.php'], (string) realpath(dirname(__DIR__, 2) . '/public')));
    }
}
