<?php

namespace App\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\HtmlString;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use RuntimeException;

class DocsPdfCommand extends Command
{
    /** Markdown file stem => PDF title. */
    private const GUIDES = [
        'web' => 'Panduan Web Inbils',
        'platform' => 'Panduan Panel Operator Inbils',
        'teknisi' => 'Panduan Aplikasi Teknisi',
        'pelanggan' => 'Panduan Aplikasi Pelanggan',
    ];

    private const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'];

    /** The cover logo, the same file the web shows. */
    private const LOGO = 'images/brand/inbils-logo.png';

    protected $signature = 'inbils:docs:pdf
        {--only= : Render one guide: web, platform, teknisi or pelanggan}
        {--dir= : Guide directory holding <name>.md (default docs/user-guide)}';

    protected $description = 'Render the user guide Markdown files in docs/user-guide into PDFs under docs/user-guide/pdf.';

    public function handle(): int
    {
        $only = (string) $this->option('only');

        if ($only !== '' && ! isset(self::GUIDES[$only])) {
            $this->error('--only must be one of: '.implode(', ', array_keys(self::GUIDES)).'.');

            return self::FAILURE;
        }

        $dir = rtrim((string) ($this->option('dir') ?: base_path('docs/user-guide')), '/');
        $names = $only !== '' ? [$only] : array_keys(self::GUIDES);
        $failed = false;

        foreach ($names as $name) {
            try {
                $target = $this->render($dir, $name);
                $this->info("{$name}: {$target}");
            } catch (RuntimeException $e) {
                $failed = true;
                $this->error("{$name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function render(string $dir, string $name): string
    {
        $source = "{$dir}/{$name}.md";

        if (! is_file($source)) {
            throw new RuntimeException("{$source} not found.");
        }

        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new TableExtension());

        $document = (new MarkdownParser($environment))->parse((string) file_get_contents($source));
        $toc = $this->prepareDocument($document, $dir);

        $pdf = Pdf::loadView('pdf.user-guide', [
            'title' => self::GUIDES[$name],
            'logo' => 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path(self::LOGO))),
            'version' => $this->version(),
            'toc' => $toc,
            'body' => new HtmlString((string) (new HtmlRenderer($environment))->renderDocument($document)),
        ])->setPaper('a4', 'portrait')->setOption('isRemoteEnabled', false);

        // Page numbers are drawn on the canvas after layout so dompdf's inline-PHP option stays off.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() / 2 - 40,
            $canvas->get_height() - 28,
            'Halaman {PAGE_NUM} dari {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            8,
            [0.4, 0.4, 0.4],
        );

        File::ensureDirectoryExists("{$dir}/pdf");
        $target = "{$dir}/pdf/{$name}.pdf";
        File::put($target, $pdf->output());

        return $target;
    }

    /**
     * Gives H1/H2 an anchor id for the table of contents and inlines local images as data URIs.
     *
     * @return list<array{id: string, level: int, text: string}>
     */
    private function prepareDocument(Node $document, string $dir): array
    {
        $toc = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof Heading && $node->getLevel() <= 2) {
                $id = 'bagian-'.(count($toc) + 1);
                $node->data->set('attributes/id', $id);
                $toc[] = ['id' => $id, 'level' => $node->getLevel(), 'text' => $this->plainText($node)];
            } elseif ($node instanceof Image) {
                $node->setUrl($this->imageDataUri($dir, $node->getUrl()));
            }
        }

        return $toc;
    }

    private function plainText(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof Text || $child instanceof Code) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    private function imageDataUri(string $dir, string $url): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $url) === 1) {
            throw new RuntimeException("Image {$url} must be a local path relative to the Markdown file.");
        }

        $path = realpath("{$dir}/".rawurldecode($url));
        $root = realpath($dir);
        $mime = self::IMAGE_TYPES[strtolower(pathinfo((string) $path, PATHINFO_EXTENSION))] ?? null;

        if ($path === false || $root === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || $mime === null) {
            throw new RuntimeException("Image {$url} is missing, outside the guide directory, or not png/jpg.");
        }

        return "data:{$mime};base64,".base64_encode((string) file_get_contents($path));
    }

    private function version(): string
    {
        $sha = trim(Process::path(base_path())->run(['git', 'rev-parse', '--short', 'HEAD'])->output());

        return ($sha !== '' ? "{$sha} · " : '').now()->format('d/m/Y');
    }
}
