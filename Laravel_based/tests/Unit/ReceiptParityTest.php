<?php

namespace Tests\Unit;

use App\Receipt\AmountExtractor;
use App\Receipt\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceiptParityTest extends TestCase
{
    public static function moneyCases(): array { return json_decode(file_get_contents(__DIR__.'/../Fixtures/parser-parity.json'),true)['money']; }
    public static function receiptCases(): iterable
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../Fixtures/parser-parity.json'),true)['receipts'] as $i=>$case) yield 'case-'.$i=>[$case];
    }

    #[DataProvider('moneyCases')]
    public function test_exact_monetary_values(string $input,?string $expected): void { self::assertSame($expected,Money::parse($input)); }

    #[DataProvider('receiptCases')]
    public function test_php_matches_original_parser(array $case): void
    {
        $actual=(new AmountExtractor)->extract($case['lines'],600,500,$case['hint']);
        foreach (['total','subtotal','tax','tip','needs_review','status','merchant','warnings','checks','line_items'] as $key) self::assertEquals($case['expected'][$key],$actual[$key],$key);
        self::assertSame(array_map(fn($c)=>[$c['amount'],$c['currency'],$c['role']],$case['expected']['candidates']),array_map(fn($c)=>[$c['amount'],$c['currency'],$c['role']],$actual['candidates']));
    }

    public function test_baseline_groups_tilted_price_columns(): void
    {
        $parser=new AmountExtractor; $lines=[];
        foreach ([[10,200,'Total',100],[450,200,'EUR 81.25',120],[10,240,'Tax',100],[450,240,'EUR 5.00',120]] as [$x,$y,$text,$width]) {
            $center=$y+tan(deg2rad(8))*($x+$width/2); $lines[]=['text'=>$text,'confidence'=>.98,'box'=>[$x,$center-10,$x+$width,$center+10]];
        }
        $result=$parser->extract($parser->groupLines($lines,8),650,500);
        self::assertSame('81.25',$result['total']['amount']); self::assertSame('5.00',$result['tax']['amount']);
    }
}
