<?php

namespace App\Receipt;

use Illuminate\Support\Facades\File;

final class ReceiptEngine
{
    public const NAME = 'RapidOCRLite C++ CPU / PP-OCRv6 small / Laravel PHP pipeline';

    public function __construct(private DocumentImages $images, private NativeRecognizer $recognizer) {}

    public static function orientationScore(array $result): float
    {
        $legible=array_sum(array_map(fn($l)=>mb_strlen($l['text'])*$l['confidence'],$result['lines']));
        return 50*(float)($result['checks']['horizontal_text_fraction']??0)+25*(float)($result['checks']['mean_text_confidence']??0)+min($legible,1000)/200+($result['total']?8:0)+(($result['checks']['arithmetic']??'')==='matched'?4:0);
    }

    public static function consensus(array $first,array $second): array
    {
        if (!$first['total'] && $second['total']) [$first,$second]=[$second,$first];
        if ($first['total']) {
            if ($first['total']===$second['total']) { $first['checks']['preprocessing_consensus']='matched'; $first['confidence']=min(.98,$first['confidence']+.025); }
            elseif ($second['total']) { $first['checks']['preprocessing_consensus']='disagreement'; $first['warnings'][]='OCR passes disagree on the total. Compare the image and candidate amounts.'; $first['confidence']=min(.60,$first['confidence']); }
            else { $first['checks']['preprocessing_consensus']='single_pass_only'; $first['warnings'][]='Only one preprocessing pass found the total. Confirm the amount.'; $first['confidence']=min(.80,$first['confidence']); }
        }
        foreach ($first['candidates'] as &$candidate) {
            foreach ($second['candidates'] as $other) {
                if ([$candidate['amount'],$candidate['currency'],$candidate['role']]===[$other['amount'],$other['currency'],$other['role']]) {
                    $candidate['observations']=2; $candidate['reasons'][]='Observed in both image preprocessing passes.'; break;
                }
            }
        }
        unset($candidate);
        foreach ($second['candidates'] as $candidate) {
            $known=false;
            foreach ($first['candidates'] as $c) if ([$c['amount'],$c['currency'],$c['role']]===[$candidate['amount'],$candidate['currency'],$candidate['role']]) { $known=true; break; }
            if (!$known) { $candidate['id']='enhanced-'.$candidate['id']; $candidate['reasons'][]='Found in the enhanced image pass.'; $first['candidates'][]=$candidate; }
        }
        $first['needs_review']=!$first['total'] || (bool)$first['warnings'] || $first['confidence']<.85;
        $first['status']=!$first['total']?'no_total':($first['needs_review']?'needs_review':'ok');
        return $first;
    }

    public function process(string $data,string $filename='receipt',?string $currency=null,bool $preview=true): array
    {
        $start=hrtime(true); $directory=storage_path('app/ocr-tmp/'.bin2hex(random_bytes(16)));
        File::makeDirectory($directory,0700,true);
        try {
            $paths=$this->images->decode($data,$directory); $pages=[];
            foreach ($paths as $pageIndex=>$source) {
                $trials=[]; $passes=0;
                foreach ([0,90,180,270] as $rotation) {
                    $image=$directory.'/rotation-'.$pageIndex.'-'.$rotation.'.png';
                    $this->images->variant($source,$image,-$rotation);
                    $trials[]=['result'=>$this->recognizer->read($image,$currency),'image'=>$image,'rotation'=>$rotation,'skew'=>0]; $passes++;
                }
                usort($trials,fn($a,$b)=>self::orientationScore($b['result'])<=>self::orientationScore($a['result']));
                $selected=$trials[0];
                $enhanced=$directory.'/enhanced-'.$pageIndex.'.png';
                $this->images->variant($selected['image'],$enhanced,0,true);
                $second=$this->recognizer->read($enhanced,$currency); $passes++;
                $page=self::consensus($selected['result'],$second);
                $page['page']=$pageIndex+1; $page['rotation']=$selected['rotation']; $page['passes']=$passes;
                if ($preview) $page['preview']=$this->images->preview($selected['image']);
                if (mb_strlen(trim($page['raw_text']))<20) { $page['warnings'][]='Very little text was readable. Upload a clearer, complete receipt.'; $page['needs_review']=true; $page['status']=$page['total']?'needs_review':'no_total'; }
                $pages[]=$page;
            }
            return ['filename'=>mb_substr(basename(str_replace('\\','/',$filename)),0,255)?:'receipt','sha256'=>hash('sha256',$data),'engine'=>self::NAME,'processing_ms'=>(int)round((hrtime(true)-$start)/1_000_000),'pages'=>$pages];
        } finally { File::deleteDirectory($directory); }
    }
}
