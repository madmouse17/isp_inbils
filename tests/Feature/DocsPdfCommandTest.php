<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocsPdfCommandTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/docs-pdf-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists("{$this->dir}/images");
        file_put_contents("{$this->dir}/images/pixel.png", base64_decode(self::PNG));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_renders_pdf_with_toc_from_h1_and_h2(): void
    {
        file_put_contents("{$this->dir}/teknisi.md", <<<'MD'
        # Mulai

        Isi pengantar.

        ## Masuk ke aplikasi

        ![Layar masuk](images/pixel.png)

        ### Detail tidak masuk daftar isi

        | Kolom | Arti |
        |---|---|
        | A | B |

        # Pekerjaan

        1. Buka SPK
        MD);
        $toc = $body = null;
        View::composer('pdf.user-guide', function ($view) use (&$toc, &$body): void {
            $toc = $view->getData()['toc'];
            $body = (string) $view->getData()['body'];
        });

        $this->artisan('inbils:docs:pdf', ['--only' => 'teknisi', '--dir' => $this->dir])->assertSuccessful();

        $this->assertSame(
            [['Mulai', 1], ['Masuk ke aplikasi', 2], ['Pekerjaan', 1]],
            array_map(fn (array $entry): array => [$entry['text'], $entry['level']], $toc),
        );
        $this->assertStringContainsString('<table>', $body);
        $this->assertStringContainsString('data:image/png;base64,', $body);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents("{$this->dir}/pdf/teknisi.pdf"));
    }

    public function test_missing_guide_fails_and_other_guides_still_render(): void
    {
        file_put_contents("{$this->dir}/web.md", "# Web\n");

        $this->artisan('inbils:docs:pdf', ['--dir' => $this->dir])->assertFailed();

        $this->assertFileExists("{$this->dir}/pdf/web.pdf");
        $this->assertFileDoesNotExist("{$this->dir}/pdf/teknisi.pdf");
    }

    public function test_unknown_only_value_fails(): void
    {
        $this->artisan('inbils:docs:pdf', ['--only' => 'admin', '--dir' => $this->dir])->assertFailed();
    }

    #[DataProvider('badImages')]
    public function test_rejects_images_outside_the_guide_directory_or_remote(string $url): void
    {
        file_put_contents("{$this->dir}/web.md", "# Web\n\n![x]({$url})\n");

        $this->artisan('inbils:docs:pdf', ['--only' => 'web', '--dir' => $this->dir])->assertFailed();

        $this->assertFileDoesNotExist("{$this->dir}/pdf/web.pdf");
    }

    /** @return array<string, array{string}> */
    public static function badImages(): array
    {
        return [
            'remote' => ['https://example.com/a.png'],
            'traversal' => ['../../../../etc/hostname.png'],
            'missing' => ['images/nope.png'],
            'wrong type' => ['images/../../../../etc/passwd'],
        ];
    }
}
