<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Db;
use App\Core\Settings;
use App\Core\ValidationException;
use App\Document\UploadValidator;
use Tests\Support\DbTestCase;

/** DOC-03 / FR-NPR-10: validasi unggahan di server (ukuran, ekstensi, MIME, nama file). */
final class UploadValidatorTest extends DbTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function file(string $name, string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);
        $this->tmp[] = $path;
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)];
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        return (string) ob_get_clean();
    }

    public function testValidPngAccepted(): void
    {
        $r = UploadValidator::validate($this->file('Contoh Botol.PNG', $this->png()), 'f', null, false);
        $this->assertSame('png', $r['ext']);
        $this->assertSame('image/png', $r['mime']);
        $this->assertSame(64, strlen($r['sha256']));
    }

    public function testPdfAccepted(): void
    {
        $r = UploadValidator::validate($this->file('spec.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"), 'f', null, false);
        $this->assertSame('application/pdf', $r['mime']);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function rejected(): array
    {
        return [
            'php script' => ['shell.php', '<?php system($_GET["c"]); ?>'],
            'php disguised as png' => ['foto.png', '<?php echo 1; ?>'],
            'double extension' => ['foto.png.php', '<?php echo 1; ?>'],
            'svg (xss)' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'html' => ['a.html', '<html><script>alert(1)</script></html>'],
            'exe' => ['setup.exe', "MZ\x90\x00"],
            'no extension' => ['README', 'text'],
            'text as pdf' => ['fake.pdf', 'just text'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testDangerousOrMismatchedFilesRejected(string $name, string $content): void
    {
        $this->expectException(ValidationException::class);
        UploadValidator::validate($this->file($name, $content), 'f', null, false);
    }

    public function testSizeLimitFromSetting(): void
    {
        Db::execute("UPDATE application_settings SET setting_value = '1' WHERE setting_key = 'upload.max_mb'");
        Settings::flush();
        $this->assertSame(1048576, UploadValidator::maxBytes());
        $big = $this->file('big.txt', str_repeat('a', 1048577));
        try {
            UploadValidator::validate($big, 'f', null, false);
            $this->fail('melebihi batas');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('1 MB', $e->errors()['f']);
        }
    }

    public function testDefaultLimitIs25Mb(): void
    {
        $this->assertSame(25 * 1048576, UploadValidator::maxBytes());
    }

    public function testExtensionMustBeInAdminWhitelist(): void
    {
        Db::execute("UPDATE application_settings SET setting_value = 'pdf' WHERE setting_key = 'upload.allowed_extensions'");
        Settings::flush();
        $this->expectException(ValidationException::class);
        UploadValidator::validate($this->file('a.png', $this->png()), 'f', null, false);
    }

    public function testAdminCannotWhitelistBlockedTypes(): void
    {
        Db::execute("UPDATE application_settings SET setting_value = 'pdf,php,svg,html' WHERE setting_key = 'upload.allowed_extensions'");
        Settings::flush();
        $this->assertSame(['pdf'], UploadValidator::allowedExtensions());
    }

    public function testRequireUploadedRejectsNonUploadedFile(): void
    {
        $this->expectException(ValidationException::class);
        UploadValidator::validate($this->file('a.png', $this->png()), 'f', null, true);
    }

    public function testUploadErrors(): void
    {
        foreach ([UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_PARTIAL] as $err) {
            try {
                UploadValidator::validate(['name' => 'x.png', 'tmp_name' => '', 'error' => $err], 'f', null, false);
                $this->fail();
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSanitizeName(): void
    {
        $this->assertSame('passwd', UploadValidator::sanitizeName('../../etc/passwd'));
        $this->assertSame('a_b_.pdf', UploadValidator::sanitizeName("a<b>.pdf"));
        $this->assertSame('x.pdf', UploadValidator::sanitizeName("C:\\Users\\x.pdf"));
        $this->assertSame('file', UploadValidator::sanitizeName("\0"));
        $this->assertLessThanOrEqual(180, mb_strlen(UploadValidator::sanitizeName(str_repeat('a', 400) . '.pdf')));
        $this->assertSame('x_t', UploadValidator::extension('part.x_t'));
    }
}
