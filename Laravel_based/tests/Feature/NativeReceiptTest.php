<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('integration')]
final class NativeReceiptTest extends TestCase
{
    public static function receipts(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/expected.json'),true) as $name=>$expected) yield $name=>[$name,$expected];
    }

    #[DataProvider('receipts')]
    public function test_every_original_receipt(string $name,array $expected): void
    {
        $directory=getenv('RECEIPT_TEST_DIR')?:dirname(base_path()).'/Receipt';
        $file=new UploadedFile($directory.'/'.$name,$name,null,null,true);
        $response=$this->post('/api/receipts',['file'=>$file,'preview'=>'false']);
        $response->assertOk(); $page=$response->json('pages.0');
        foreach ($expected as $key=>$value) {
            if ($key==='currency') self::assertSame($value,$page['total']['currency']);
            else self::assertSame($value,$page[$key]['amount']??null,$page['raw_text']);
        }
        self::assertNotEmpty($page['candidates']); self::assertNull($page['preview']);
    }

    public static function rotations(): iterable
    {
        foreach (['receipt-type-1.png','receipt-type-3.png'] as $name) foreach ([90,180,270] as $angle) yield $name.'-'.$angle=>[$name,$angle];
    }

    #[DataProvider('rotations')]
    public function test_rotated_table_images(string $name,int $angle): void
    {
        $directory=getenv('RECEIPT_TEST_DIR')?:dirname(base_path()).'/Receipt';
        $temporary=storage_path('app/rotation-test-'.bin2hex(random_bytes(8)).'.png');
        try {
            app(\App\Receipt\DocumentImages::class)->variant($directory.'/'.$name,$temporary,-$angle);
            $result=app(\App\Receipt\ReceiptEngine::class)->process(file_get_contents($temporary),'renamed.png',null,false);
        } finally { if (is_file($temporary)) unlink($temporary); }
        self::assertSame('79.40',$result['pages'][0]['total']['amount']);
        self::assertSame('10.00',$result['pages'][0]['tax']['amount']);
    }

    public function test_blank_image_abstains(): void
    {
        $temporary=storage_path('app/blank-test-'.bin2hex(random_bytes(8)).'.png');
        try {
            app(\App\Receipt\NativeProcess::class)->run([config('receipt.magick'),'-size','200x300','xc:white',$temporary]);
            $result=app(\App\Receipt\ReceiptEngine::class)->process(file_get_contents($temporary),'blank.png',null,false);
        } finally { if (is_file($temporary)) unlink($temporary); }
        self::assertNull($result['pages'][0]['total']); self::assertTrue($result['pages'][0]['needs_review']);
    }

    private function pdf(): string
    {
        $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>',7=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        foreach ([3=>['FIRST SHOP','Total USD 12.75'],5=>['SECOND SHOP','Total CHF 83.20']] as $id=>$lines) {
            $stream="BT /F1 28 Tf 35 220 Td (".$lines[0].") Tj 0 -70 Td (".$lines[1].") Tj ET";
            $objects[$id]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 400 300] /Resources << /Font << /F1 7 0 R >> >> /Contents '.($id+1).' 0 R >>';
            $objects[$id+1]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
        }
        ksort($objects); $pdf="%PDF-1.4\n"; $offset=[0];
        foreach ($objects as $id=>$object) { $offset[$id]=strlen($pdf); $pdf.=$id." 0 obj\n".$object."\nendobj\n"; }
        $xref=strlen($pdf); $pdf.="xref\n0 8\n0000000000 65535 f \n";
        for($i=1;$i<=7;$i++) $pdf.=sprintf("%010d 00000 n \n",$offset[$i]);
        return $pdf."trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }

    public function test_two_page_pdf(): void
    {
        $result=app(\App\Receipt\ReceiptEngine::class)->process($this->pdf(),'two.pdf',null,false);
        self::assertCount(2,$result['pages']);
        self::assertSame(['12.75','83.20'],array_column(array_column($result['pages'],'total'),'amount'));
    }

    public function test_tiff_and_page_limit(): void
    {
        $directory=storage_path('app/test-'.bin2hex(random_bytes(8))); mkdir($directory,0700,true);
        try {
            $images=app(\App\Receipt\DocumentImages::class)->decode($this->pdf(),$directory);
            $process=app(\App\Receipt\NativeProcess::class); $path=$directory.'/two.tiff';
            $process->run([config('receipt.magick'),...$images,$path]);
            $result=app(\App\Receipt\ReceiptEngine::class)->process(file_get_contents($path),'two.tiff',null,false);
            self::assertSame(['12.75','83.20'],array_column(array_column($result['pages'],'total'),'amount'));
            $process->run([config('receipt.magick'),...array_fill(0,6,$images[0]),$directory.'/six.tiff']);
            $this->expectException(\App\Receipt\InvalidDocument::class);
            app(\App\Receipt\DocumentImages::class)->decode(file_get_contents($directory.'/six.tiff'),$directory);
        } finally { \Illuminate\Support\Facades\File::deleteDirectory($directory); }
    }
}
