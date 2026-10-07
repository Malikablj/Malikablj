<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kontrak UI (PRD §11): token warna terang/gelap, mode gelap sejati tanpa kilatan, font Inter lokal,
 * fokus terlihat, reduce motion, durasi animasi, target sentuh, logo terang/gelap, tanpa AI Assistant.
 * Pemeriksaan visual nyata (kontras, scroll horizontal, permukaan terang) ada di tests/browser/ui_audit.py.
 */
final class UiContractTest extends TestCase
{
    private static string $css;
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        self::$css = (string) file_get_contents(self::$root . '/public/assets/css/app.css');
    }

    /** @return array<string,string> token di dalam satu blok selektor */
    private static function block(string $selector): array
    {
        $start = strpos(self::$css, $selector . ' {');
        self::assertNotFalse($start, 'blok ' . $selector);
        $body = substr(self::$css, $start, (int) strpos(self::$css, '}', $start) - $start);
        preg_match_all('/--([a-z0-9-]+):\s*([^;]+);/i', $body, $m);
        return array_combine($m[1], array_map('trim', $m[2]));
    }

    public function testLightTokensMatchPrd(): void
    {
        $t = self::block(':root');
        foreach (['bg' => '#F7F7F5', 'surface' => '#FFFFFF', 'text' => '#1D1D1F', 'text-2' => '#6E6E73', 'text-muted' => '#86868B', 'border' => '#E5E5E7', 'accent' => '#3B63F3'] as $k => $v) {
            $this->assertSame($v, strtoupper($t[$k] ?? ''), '--' . $k);
        }
    }

    public function testDarkTokensMatchPrdForExplicitAndSystemTheme(): void
    {
        $expected = ['bg' => '#000000', 'surface' => '#111111', 'surface-2' => '#1C1C1E', 'text' => '#F5F5F7', 'text-2' => '#A1A1A6', 'text-muted' => '#86868B', 'border' => '#2C2C2E', 'accent' => '#5E7BFF'];
        $explicit = self::block(':root[data-theme="dark"]');
        $system = self::block(':root:not([data-theme="light"]):not([data-theme="dark"])');
        foreach ($expected as $k => $v) {
            $this->assertSame($v, strtoupper($explicit[$k] ?? ''), 'dark --' . $k);
            $this->assertSame($v, strtoupper($system[$k] ?? ''), 'system dark --' . $k);
        }
        $this->assertSame(array_keys($explicit), array_keys(array_intersect_key($explicit, $system)), 'mode sistem & eksplisit memiliki token yang sama');
        $this->assertStringContainsString('color-scheme: dark', self::$css, 'kontrol native mengikuti mode gelap');
    }

    public function testNoThemeFlashAndThemeColor(): void
    {
        $head = (string) file_get_contents(self::$root . '/includes/layout/head.php');
        $this->assertStringContainsString('name="theme-color"', $head);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]*"[^>]*>[\s\S]*data-theme-pref[\s\S]*<\/script>[\s\S]*<link rel="stylesheet"/', $head, 'tema diterapkan sebelum CSS dimuat (tanpa kilatan terang)');
    }

    public function testInterIsSelfHostedAndNoExternalAssets(): void
    {
        $this->assertMatchesRegularExpression('/@font-face\s*{[^}]*font-family:\s*"Inter"[^}]*url\(["\']?\.\.\/fonts\/inter-latin-wght-normal\.woff2/', self::$css);
        $this->assertFileExists(self::$root . '/public/assets/fonts/inter-latin-wght-normal.woff2');
        $this->assertDoesNotMatchRegularExpression('/url\(["\']?https?:/i', self::$css, 'tidak bergantung internet');
        foreach (glob(self::$root . '/includes/layout/*.php') as $f) {
            $this->assertDoesNotMatchRegularExpression('/<(script|link)[^>]+(src|href)="https?:\/\//i', (string) file_get_contents($f), basename($f));
        }
    }

    public function testFocusVisibleReducedMotionAndDurations(): void
    {
        $this->assertMatchesRegularExpression('/:focus-visible\s*{[^}]*box-shadow:\s*var\(--focus\)/', self::$css);
        $this->assertMatchesRegularExpression('/@media \(prefers-reduced-motion: reduce\)\s*{\s*\*, \*::before, \*::after\s*{[^}]*animation-duration:\s*0\.01ms/', self::$css);
        preg_match_all('/animation:\s*([a-z-]+)\s+([0-9.]+)(ms|s)/', self::$css, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as [, $name, $num, $unit]) {
            if ($name === 'skeleton') {
                continue; // indikator memuat (sekali jalan), bukan transisi
            }
            $ms = $unit === 's' ? (float) $num * 1000 : (float) $num;
            $this->assertTrue($ms >= 150 && $ms <= 250, "animasi $name {$ms}ms di luar 150–250 ms (PRD §11.5)");
        }
        $this->assertStringNotContainsString('infinite', self::$css, 'tidak ada animasi berulang tanpa henti');
    }

    public function testTouchTargetsAndResponsiveTables(): void
    {
        $this->assertStringContainsString('--touch: 44px', self::$css);
        $this->assertMatchesRegularExpression('/@media \(pointer: coarse\)\s*{[^@]*min-height: var\(--touch\)/', self::$css);
        $this->assertStringContainsString('.table-cards thead { display: none; }', self::$css, 'daftar menjadi kartu di HP');
        $this->assertMatchesRegularExpression('/@media \(max-width: 1100px\) and \(min-width: 641px\)\s*{\s*\.table-wrap \.table td:first-child { position: sticky;/', self::$css, 'kolom pertama menempel di tablet');
    }

    public function testLogoVariantsAndNoAiAssistant(): void
    {
        foreach (['logo-light.png', 'logo-dark.png'] as $f) {
            $this->assertFileExists(self::$root . '/public/assets/images/' . $f);
        }
        foreach (['/public/login.php', '/includes/layout/sidebar.php'] as $f) {
            $src = (string) file_get_contents(self::$root . $f);
            $this->assertStringContainsString('logo-light.png', $src, $f);
            $this->assertStringContainsString('logo-dark.png', $src, $f);
        }
        $hits = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$root . '/public', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (in_array($file->getExtension(), ['php', 'js'], true) && preg_match('/AI Assistant|ai-assistant|ai_assistant/i', (string) file_get_contents($file->getPathname()))) {
                $hits[] = $file->getPathname();
            }
        }
        $this->assertSame([], $hits, 'UAT-25: tidak ada menu/halaman/endpoint AI Assistant');
    }
}
