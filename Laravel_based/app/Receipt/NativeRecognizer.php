<?php

namespace App\Receipt;

final class NativeRecognizer
{
    public function __construct(private NativeProcess $process,private AmountExtractor $extractor) {}

    public function read(string $image,?string $currency=null): array
    {
        $ppm=$image.'.ppm';
        $this->process->run([config('receipt.magick'),$image,'-depth','8',$ppm]);
        $models=config('receipt.models');
        $records=json_decode($this->process->run([config('receipt.native_binary'),$models.'/PP-OCRv6_det_small.onnx',$models.'/PP-OCRv6_rec_small.onnx',$models.'/dict.txt',$ppm]),true,512,JSON_THROW_ON_ERROR);
        unlink($ppm);
        $detections=[]; $angles=[]; $horizontal=0; $total=0; $confidence=0;
        foreach ($records as $record) {
            $polygon=$record['polygon']; $x=array_column($polygon,0); $y=array_column($polygon,1);
            $width=max($x)-min($x); $height=max($y)-min($y); $chars=mb_strlen(trim($record['text']));
            $total+=$chars; $confidence+=$chars*$record['confidence'];
            if ($width>$height) $horizontal+=$chars;
            $angle=rad2deg(atan2($polygon[1][1]-$polygon[0][1],$polygon[1][0]-$polygon[0][0]));
            if ($width>2*$height && $chars>=4 && abs($angle)<=20) $angles[]=$angle;
            $detections[]=['text'=>$record['text'],'confidence'=>$record['confidence'],'box'=>[min($x),min($y),max($x),max($y)]];
        }
        sort($angles); $n=count($angles); $skew=$n?($angles[intdiv($n-1,2)]+$angles[intdiv($n,2)])/2:0;
        [$width,$height]=getimagesize($image);
        $result=$this->extractor->extract($this->extractor->groupLines($detections,$skew),$width,$height,$currency);
        $result['checks']['horizontal_text_fraction']=sprintf('%.4f',$horizontal/max(1,$total));
        $result['checks']['mean_text_confidence']=sprintf('%.4f',$confidence/max(1,$total));
        $result['checks']['line_skew_degrees']=sprintf('%.2f',$skew);
        return $result;
    }
}
