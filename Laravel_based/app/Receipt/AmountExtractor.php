<?php

namespace App\Receipt;

use Normalizer;

/** PHP port of the receipt row parser. Printed text is data, never instructions. */
final class AmountExtractor
{
    private const NUMBER = <<<'RX'
~(?<![\w.,:/])([-−]?\(?\d{1,3}(?:[ '’.,]\d{3})+[.,]\d{1,2}\)?|[-−]?\(?\d+[.,]\d{1,2}\)?)(?![\w\d.,:/]|\s*%)~u
RX;
    private const INTEGER = <<<'RX'
~(?<![\w.,:/])(-?(?:\d{1,3}(?:[ '’.,]\d{3})+|\d{1,9}))(?![\w.,:/]|\s*%)~u
RX;
    private const CURRENCY = '~\b(?:USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)\b|US\$|[€£₹$¥]~iu';
    private const TOTAL = '~\b(?:grand\s*total|total(?:\s+(?:due|amount|ttc))?|amount\s*(?:due|payable)|balance\s*due|net\s*a\s*payer|gesamt(?:betrag)?|summe|endbetrag|montant\s*du)\b~iu';
    private const SUBTOTAL = '~\b(?:sub[ -]?total|sous[ -]?total|zwischensumme)\b~iu';
    private const TAX = '~\b(?:tax|vat|mwst|tva|gst|cgst|sgst|igst|sales\s*tax)\b~iu';
    private const TIP = '~\b(?:tip|gratuity|pourboire)\b~iu';
    private const PAYMENT = '~\b(?:cash|tendered|paid|payment|remitted|debit|credit|visa|mastercard|purchase)\b~iu';
    private const EXCHANGE = '~\b(?:equivalent|entspricht|exchange|converted|conversion|in\s+euro)\b~iu';
    private const EXCLUDE = '~\b(?:tel|fax|phone|account|approval|transaction|receipt\s*\#|rech\.?\s*nr|tax\s*id|mwst\s*nr|date|time|aid|pin|table|tisch)(?!\w)~iu';
    private const CHANGE = '~\b(?:change|rendu|ruckgeld)\b~iu';
    private const DISCOUNT = '~\b(?:savings|saved|discounts?|coupon|rabatt|remise)\b~iu';

    public static function normalize(string $text): string
    {
        return preg_replace('~\p{M}~u', '', Normalizer::normalize($text, Normalizer::FORM_KD)) ?? $text;
    }

    private function matches(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        return $matches[1] ?? $matches[0] ?? [];
    }

    private function currency(string $text, int $position, ?string $fallback): ?string
    {
        $matches = $this->matches(self::CURRENCY, $text);
        if (! $matches) return $fallback;
        usort($matches, fn ($a, $b) => min(abs($a[1] - $position), abs($a[1] + strlen($a[0]) - $position)) <=> min(abs($b[1] - $position), abs($b[1] + strlen($b[0]) - $position)));
        $token = strtoupper($matches[0][0]);
        return match ($token) {
            '€' => 'EUR', '£' => 'GBP', '₹' => 'INR', 'US$' => 'USD',
            '$' => in_array($fallback, ['USD', 'CAD', 'AUD', 'NZD'], true) ? $fallback : null,
            '¥' => in_array($fallback, ['JPY', 'CNY'], true) ? $fallback : null,
            default => $token,
        };
    }

    private function documentCurrency(array $lines, ?string $hint): ?string
    {
        if ($hint) return $hint;
        $text = implode("\n", array_column($lines, 'text'));
        $codes = [];
        foreach ($this->matches(self::CURRENCY, $text) as [$token]) {
            if (strlen($token) === 3 && ctype_alpha($token)) {
                $code = strtoupper($token); $codes[$code] = ($codes[$code] ?? 0) + 1;
            }
        }
        if ($codes) { arsort($codes); return array_key_first($codes); }
        if (preg_match('~UNITED\s*STATES|USPS|\b[A-Z]{2}\s+\d{5}(?:-\d{4})?\b~iu', $text)) return 'USD';
        foreach (['€' => 'EUR', '£' => 'GBP', '₹' => 'INR'] as $symbol => $code) {
            if (str_contains($text, $symbol)) return $code;
        }
        return null;
    }

    /** Merge along a receipt baseline without moving the original evidence boxes. */
    public function groupLines(array $detections, float $angle = 0): array
    {
        $slope = tan(deg2rad($angle));
        $baseline = fn ($line) => ($line['box'][1] + $line['box'][3]) / 2 - $slope * ($line['box'][0] + $line['box'][2]) / 2;
        usort($detections, fn ($a, $b) => $baseline($a) <=> $baseline($b));
        $rows = [];
        foreach ($detections as $box) {
            $found = false;
            for ($i = count($rows) - 1; $i >= max(0, count($rows) - 3); $i--) {
                $row = $rows[$i];
                $ry = array_sum(array_map($baseline, $row)) / count($row);
                $rh = array_sum(array_map(fn ($r) => $r['box'][3] - $r['box'][1], $row)) / count($row);
                if (abs($baseline($box) - $ry) <= .52 * min($box['box'][3] - $box['box'][1], $rh)) {
                    $rows[$i][] = $box; $found = true; break;
                }
            }
            if (! $found) $rows[] = [$box];
        }
        return array_map(function ($row) {
            usort($row, fn ($a, $b) => $a['box'][0] <=> $b['box'][0]);
            $chars = array_sum(array_map(fn ($r) => mb_strlen($r['text']), $row));
            return [
                'text' => implode(' ', array_column($row, 'text')),
                'confidence' => array_sum(array_map(fn ($r) => $r['confidence'] * mb_strlen($r['text']), $row)) / max(1, $chars),
                'box' => [min(array_column(array_column($row, 'box'), 0)), min(array_column(array_column($row, 'box'), 1)), max(array_column(array_column($row, 'box'), 2)), max(array_column(array_column($row, 'box'), 3))],
            ];
        }, $rows);
    }

    public function extract(array $lines, int $width, int $height, ?string $hint = null): array
    {
        $currency = $this->documentCurrency($lines, $hint); $candidates = []; $items = [];
        foreach ($lines as $row => $line) {
            $text = self::normalize($line['text']);
            $labelRepaired=false;
            if(preg_match('~^\s*([A-Za-z01|]{4,6})(?=\s*:?\s*(?:(?:USD|CHF|EUR|GBP)\s*|[€£₹$]\s*)?[-(]?\d)~u',$text,$label)) {
                $word=strtolower(strtr($label[1],['0'=>'o','1'=>'l','|'=>'l']));
                if($word!=='total' && levenshtein($word,'total')===1) {
                    $text=preg_replace('~^\s*'.preg_quote($label[1],'~').'~u','Total',$text,1); $labelRepaired=true;
                }
            }
            $text = preg_replace('~\b(USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)(?=[-\d(])~i', '$1 ', $text);
            $text = preg_replace('~(?<=\d)(USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)\b~i', ' $1', $text);
            $repaired = preg_replace_callback('~(?<=\d)([.,])([0-9OoIlS]{2})(?!\w)~u', fn ($m) => $m[1].strtr($m[2], ['O'=>'0','o'=>'0','I'=>'1','l'=>'1','S'=>'5']), $text);
            $repaired=preg_replace('~((?:USD|CHF|EUR|GBP)\s*|[€£₹$])(\d+)-(\d{2})(?!\d)~u','$1$2.$3',$repaired);
            $matches = $this->matches(self::NUMBER, $repaired); $role = 'amount';
            if (preg_match(self::EXCLUDE, $text)) continue;
            foreach (['exchange'=>self::EXCHANGE, 'subtotal'=>self::SUBTOTAL, 'change'=>self::CHANGE, 'discount'=>self::DISCOUNT, 'tax'=>self::TAX, 'tip'=>self::TIP, 'total'=>self::TOTAL, 'payment'=>self::PAYMENT] as $kind => $pattern) {
                if ($kind === 'total' && preg_match('~\btotal\s*(?:items|qty|quantity|points)\b~i', $text)) continue;
                if (preg_match($pattern, $text)) { $role = $kind; break; }
            }
            if ($role === 'amount' && $row && ! preg_match('~[a-zA-Z]{4}~', preg_replace(self::CURRENCY, '', $text))) {
                $prior = self::normalize($lines[$row - 1]['text']);
                if (preg_match(self::TOTAL, $prior) && ! preg_match(self::SUBTOTAL, $prior) && ! preg_match(self::NUMBER, $prior)
                    && $line['box'][1] - $lines[$row - 1]['box'][3] < 2.5 * ($line['box'][3] - $line['box'][1])) $role = 'total';
            }
            if (! $matches && (in_array($role, ['total','subtotal','tax','tip','payment'], true) || preg_match(self::CURRENCY, $text))) $matches = $this->matches(self::INTEGER, $repaired);
            foreach ($matches as $index => [$raw, $position]) {
                $value = Money::parse($raw); if ($value === null) continue;
                $current = $role;
                if (in_array($role, ['total','subtotal','tax','tip','payment','change'], true) && $index !== count($matches) - 1) $current = $role === 'tax' ? 'tax_base' : 'amount';
                $score = ['total'=>80,'subtotal'=>20,'tax'=>12,'tip'=>10,'payment'=>28,'exchange'=>-20,'change'=>-20,'tax_base'=>-5][$current] ?? 8;
                $reasons = ["Classified as {$current} from the receipt row."];
                if ($repaired !== $text) $reasons[] = 'Ambiguous OCR glyphs were repaired within a decimal amount.';
                if ($labelRepaired) $reasons[]='The total label was repaired from a close OCR spelling.';
                if ($current === 'total') {
                    if (preg_match('~total\s+to\s+pay~i',$text)) $score+=12;
                    elseif (preg_match('~grand|due|payable|endbetrag~i', $text)) $score += 6;
                    $score += min(5, 5 * $line['box'][1] / max($height, 1));
                    $reasons[] = 'Explicit payable-total label.';
                }
                $candidates[] = ['id'=>"r{$row}-a{$index}", 'amount'=>$value, 'currency'=>$this->currency($repaired, $position, $currency), 'role'=>$current,
                    'score'=>round($score + $line['confidence'] * 8, 2), 'ocr_confidence'=>round($line['confidence'], 4), 'source_text'=>$line['text'], 'box'=>$line['box'], 'reasons'=>$reasons, 'observations'=>1];
            }
            if ($role === 'amount' && $matches) {
                $prefix = trim(substr($text, 0, $matches[0][1]));
                if (preg_match('~[a-zA-Z]{2}~', $prefix) && ! preg_match('~@|https?~', $prefix)) {
                    $hasQuantity = preg_match('~^\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*~u', $prefix, $quantity);
                    if ($hasQuantity || count($matches) >= 2) {
                        $amounts = array_map(fn ($m) => Money::parse($m[0]), $matches);
                        if (end($amounts) !== null) $items[] = [
                            'description'=>trim(preg_replace('~\s+a\s*$~', '', preg_replace(self::CURRENCY, '', $hasQuantity ? substr($prefix, strlen($quantity[0])) : $prefix))),
                            'quantity'=>$hasQuantity ? str_replace(',', '.', $quantity[1]) : null,
                            'unit_price'=>count($amounts) >= 2 ? $amounts[count($amounts)-2] : null,
                            'amount'=>end($amounts), 'currency'=>$this->currency($text, end($matches)[1], $currency), 'source_text'=>$line['text'],
                        ];
                    }
                }
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $totals = array_values(array_filter($candidates, fn ($c) => $c['role'] === 'total'));
        $best = $totals[0] ?? null; $warnings = []; $checks = []; $confidence = 0.0;
        if ($best) {
            $confidence = min(.96, .70 + .25 * $best['ocr_confidence']);
            if (count(array_unique(array_map(fn ($c) => $c['amount'].'|'.$c['currency'], $totals))) > 1) { $warnings[] = 'Conflicting printed totals were found; confirm the payable amount.'; $confidence = min($confidence, .64); }
            if ($best['currency'] === null) { $warnings[] = 'Currency is ambiguous or absent. Select the receipt currency.'; $confidence = min($confidence, .74); }
            if ($best['ocr_confidence'] < .85) { $warnings[] = 'The total row has low OCR confidence.'; $confidence = min($confidence, .70); }
            if (str_contains(implode(' ', $best['reasons']), 'glyphs')) { $warnings[] = 'Ambiguous characters in the total were repaired. Confirm the printed digits.'; $confidence = min($confidence, .75); }
            if (str_contains(implode(' ', $best['reasons']), 'total label was repaired')) { $warnings[]='The total label contained an OCR spelling error. Confirm the printed total.'; $confidence=min($confidence,.75); }
            $comparable = array_filter($items, fn ($i) => $i['currency'] === $best['currency']);
            if ($comparable) {
                $sum = array_sum(array_map(fn ($i) => Money::cents($i['amount']), $comparable));
                $checks['line_item_sum'] = Money::format($sum);
                $checks['line_items_match_total'] = $sum === Money::cents($best['amount']) ? 'true' : 'false';
            }
        } else $warnings[] = 'No explicit total could be read. Candidate amounts require human review.';
        $field = function ($role) use ($candidates, $best) {
            $found = array_values(array_filter($candidates, fn ($c) => $c['role'] === $role && (! $best || $c['currency'] === $best['currency'])));
            if (! $found) return null;
            if ($role === 'tax' && count($found) > 1) {
                $explicit = array_values(array_filter($found, fn ($c) => preg_match('~total\s*(?:tax|gst|vat)~i', $c['source_text'])));
                if ($explicit) $found = $explicit;
                else {
                    $sum=array_sum(array_map(fn($c)=>Money::cents($c['amount']),$found));
                    $byPosition=$found; usort($byPosition,fn($a,$b)=>$a['box'][1]<=>$b['box'][1]); $last=end($byPosition);
                    $subtotals=array_values(array_filter($candidates,fn($c)=>$c['role']==='subtotal'&&$c['currency']===$last['currency']));
                    $tips=array_values(array_filter($candidates,fn($c)=>$c['role']==='tip'&&$c['currency']===$last['currency']));
                    // A tax summary is not another charge. Require both component
                    // agreement and subtotal/total arithmetic before deduplicating it.
                    if(count($found)>=3 && $best && $subtotals && Money::cents($last['amount'])*2===$sum
                        && Money::cents($subtotals[0]['amount'])+Money::cents($last['amount'])+($tips?Money::cents($tips[0]['amount']):0)===Money::cents($best['amount'])) {
                        return ['amount'=>$last['amount'],'currency'=>$last['currency']];
                    }
                    return ['amount'=>Money::format($sum),'currency'=>$found[0]['currency']];
                }
            }
            return ['amount'=>$found[0]['amount'], 'currency'=>$found[0]['currency']];
        };
        $subtotal = $field('subtotal'); $tax = $field('tax'); $tip = $field('tip');
        if ($best && $subtotal && $tax) {
            $expected = Money::cents($subtotal['amount']) + Money::cents($tax['amount']) + ($tip ? Money::cents($tip['amount']) : 0);
            $checks['subtotal_plus_tax_and_tip'] = Money::format($expected);
            $checks['arithmetic'] = $expected === Money::cents($best['amount']) ? 'matched' : 'mismatch';
            if ($checks['arithmetic'] === 'mismatch') { $warnings[] = 'Subtotal + tax + tip does not match the total; review discounts or other charges.'; $confidence = min($confidence, .70); }
        }
        $merchant = null;
        foreach (array_slice($lines, 0, 5) as $line) {
            if (preg_match('~[A-Za-z]{3}~', $line['text']) && ! preg_match(self::EXCLUDE, self::normalize($line['text']))) { $merchant = $line['text']; break; }
        }
        $review = ! $best || $confidence < .85 || (bool) $warnings;
        return ['page'=>1,'status'=>! $best ? 'no_total' : ($review ? 'needs_review' : 'ok'), 'total'=>$best ? ['amount'=>$best['amount'],'currency'=>$best['currency']] : null,
            'subtotal'=>$subtotal,'tax'=>$tax,'tip'=>$tip,'merchant'=>$merchant,'confidence'=>round($confidence,4),'needs_review'=>$review,'warnings'=>$warnings,'checks'=>$checks,
            'line_items'=>$items,'candidates'=>$candidates,'lines'=>$lines,'raw_text'=>implode("\n",array_column($lines,'text')), 'rotation'=>0,'passes'=>0,'width'=>$width,'height'=>$height,'preview'=>null];
    }
}
