<?php

namespace App\Receipt;

final class DocumentImages
{
    public function __construct(private NativeProcess $process) {}

    public function decode(string $data, string $directory): array
    {
        if ($data === '') throw new InvalidDocument('The uploaded file is empty.');
        if (strlen($data) > config('receipt.max_bytes')) throw new InvalidDocument('File exceeds the 20 MiB limit.');
        $input = $directory.'/source'; file_put_contents($input, $data); chmod($input, 0600);
        try {
            if (str_starts_with($data, '%PDF-')) return $this->pdf($input, $directory);
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            if (!in_array($mime,['image/jpeg','image/png','image/webp','image/tiff','image/avif'],true)) throw new InvalidDocument('Use JPEG, PNG, WebP, TIFF, AVIF, or PDF.');
            $description = $this->process->run([config('receipt.magick'), 'identify', '-ping', '-format', '%m %w %h\n', $input]);
            $frames = array_values(array_filter(explode("\n", trim($description))));
            if (count($frames) > config('receipt.max_pages')) throw new InvalidDocument('Documents may contain at most 5 pages.');
            $pages = [];
            foreach ($frames as $i => $frame) {
                [$format, $width, $height] = explode(' ', $frame);
                if (! in_array($format, ['JPEG','PNG','WEBP','TIFF','AVIF'], true)) throw new InvalidDocument('Use JPEG, PNG, WebP, TIFF, AVIF, or PDF.');
                $this->checkSize((int) $width, (int) $height);
                $page = $directory.'/page-'.$i.'.png';
                $this->process->run([config('receipt.magick'), $input.'['.$i.']', '-auto-orient', '-background','white','-alpha','remove','-alpha','off','-colorspace','sRGB','-resize','1000x1000^','-resize','2200x2200>', $page]);
                $pages[] = $page;
            }
            if (! $pages) throw new InvalidDocument('No image pages were found.');
            return $pages;
        } catch (InvalidDocument $e) { throw $e; }
        catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Native document decoding failed',['cause'=>$e->getMessage()]);
            throw new InvalidDocument('Cannot decode this document. It may be corrupt, encrypted, or unsupported.', 0, $e);
        }
    }

    private function checkSize(int $width, int $height): void
    {
        if ($width * $height > config('receipt.max_pixels')) throw new InvalidDocument('Image exceeds the 25 megapixel limit.');
        if (min($width, $height) < 40) throw new InvalidDocument('Image is too small to read (minimum 40 × 40 pixels).');
    }

    private function pdf(string $input, string $directory): array
    {
        $info = $this->process->run([config('receipt.pdfinfo'), $input]);
        if (! preg_match('~^Pages:\s+(\d+)~m', $info, $matches)) throw new InvalidDocument('Cannot read this PDF.');
        $count = (int) $matches[1];
        if ($count < 1 || $count > config('receipt.max_pages')) throw new InvalidDocument('PDFs must contain 1–5 pages.');
        // Inspect each page size before rendering it at 144 dpi.
        for ($i=1; $i <= $count; $i++) {
            $pageInfo = $this->process->run([config('receipt.pdfinfo'), '-f',(string)$i,'-l',(string)$i, $input]);
            if (! preg_match('~(?:Page\s+\d+\s+size|Page size):\s+([\d.]+)\s+x\s+([\d.]+)~', $pageInfo, $size)) throw new InvalidDocument('Cannot determine PDF page size.');
            $this->checkSize((int) ceil((float)$size[1]*2), (int) ceil((float)$size[2]*2));
            $this->process->run([config('receipt.pdftoppm'), '-f',(string)$i,'-l',(string)$i,'-singlefile','-r','144','-png', $input, $directory.'/pdf-'.$i]);
        }
        return array_map(fn ($i) => $directory.'/pdf-'.$i.'.png', range(1,$count));
    }

    public function variant(string $source, string $target, float $degrees = 0, bool $enhance = false): void
    {
        $args = [config('receipt.magick'), $source, '-background','white','-rotate',(string)$degrees];
        if ($enhance) $args = array_merge($args, ['-colorspace','Gray','-normalize','-unsharp','0x.6']);
        $this->process->run([...$args, $target]);
    }

    public function preview(string $source): string
    {
        return 'data:image/jpeg;base64,'.base64_encode($this->process->run([config('receipt.magick'),$source,'-resize','1400x1800>','-quality','85','jpeg:-']));
    }
}
