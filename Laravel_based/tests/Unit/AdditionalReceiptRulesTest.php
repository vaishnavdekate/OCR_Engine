<?php

namespace Tests\Unit;

use App\Receipt\AmountExtractor;
use PHPUnit\Framework\TestCase;

final class AdditionalReceiptRulesTest extends TestCase
{
    private function extract(array $texts): array
    {
        $lines=[];foreach($texts as $i=>$text)$lines[]=['text'=>$text,'confidence'=>.98,'box'=>[10,$i*35,500,$i*35+25]];
        return (new AmountExtractor)->extract($lines,600,600);
    }

    public function test_tax_summary_is_not_counted_twice(): void
    {
        $r=$this->extract(['Subtotal USD 120.00','State Tax USD 12.00','City Tax USD 3.00','Sales Tax USD 15.00','Total USD 135.00']);
        self::assertSame('15.00',$r['tax']['amount']); self::assertSame('matched',$r['checks']['arithmetic']);
    }
    public function test_three_independent_taxes_are_still_added(): void
    {
        $r=$this->extract(['Subtotal USD 100.00','State Tax USD 5.00','City Tax USD 0.00','Sales Tax USD 5.00','Total USD 110.00']);
        self::assertSame('10.00',$r['tax']['amount']);
    }
    public function test_payable_total_outranks_intermediate_balance(): void
    {
        $r=$this->extract(['Balance due GBP 42.00','TOTAL TO PAY GBP 38.00','Balance due £38-00','TOTAL DISCOUNTS GBP -4.00']);
        self::assertSame('38.00',$r['total']['amount']);
        self::assertTrue($r['needs_review']);
    }
    public function test_close_total_label_is_repaired_and_flagged(): void
    {
        $r=$this->extract(['Yotal: EUR 42.50']); self::assertSame('42.50',$r['total']['amount']); self::assertTrue($r['needs_review']);
        self::assertNull($this->extract(['Hotel EUR 42.50'])['total']);
    }
}
