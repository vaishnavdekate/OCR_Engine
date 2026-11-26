<?php

// Repeatable live HTTP verification using PHP cURL against the running application.
$options=getopt('',['url::','receipts::','output::','snapshot::']);
$root=dirname(__DIR__);
$url=rtrim($options['url']??'http://127.0.0.1:8766','/');
$directory=$options['receipts']??dirname($root).'/Receipt';
$output=$options['output']??$root.'/artifacts/live-receipts';
if(!is_dir($output))mkdir($output,0700,true);
$expected=json_decode(file_get_contents($root.'/tests/Fixtures/expected.json'),true,512,JSON_THROW_ON_ERROR);
$records=[];
$snapshot=isset($options['snapshot'])?json_decode(file_get_contents($options['snapshot']),true,512,JSON_THROW_ON_ERROR):null;
$paths=$snapshot?array_map(fn($name)=>$directory.'/'.$name,array_keys($snapshot)):glob($directory.'/*');
foreach($paths as $path) {
    $file=new SplFileInfo($path);
    if(!$file->isFile()||str_starts_with($file->getFilename(),'.'))continue;
    $name=$file->getFilename(); $start=microtime(true); $curl=curl_init($url.'/api/receipts');
    $headers=[];if(getenv('RECEIPT_API_TOKEN'))$headers[]='Authorization: Bearer '.getenv('RECEIPT_API_TOKEN');
    curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['file'=>new CURLFile($file->getPathname()),'preview'=>$file->getExtension()==='avif'?'true':'false'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>$headers]);
    $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);unset($curl);
    $response=json_decode($body?:'{}',true);$page=$response['pages'][0]??[];$checks=[];
    foreach($expected[$name]??[] as $key=>$value)$checks[$key]=$key==='currency'?($page['total']['currency']??null)===$value:($page[$key]['amount']??null)===$value;
    $passed=$status===200&&(bool)$checks&&!in_array(false,$checks,true)&&(!$snapshot||hash_file('sha256',$file->getPathname())===$snapshot[$name]);
    $record=['file'=>$name,'input_sha256'=>hash_file('sha256',$file->getPathname()),'http_status'=>$status,'processing_ms'=>(int)round((microtime(true)-$start)*1000),'expected'=>$expected[$name]??null,'field_checks'=>$checks,'passed'=>$passed,'response'=>$response,'error'=>$error?:null];
    $records[]=$record;
    file_put_contents($output.'/'.$name.'.json',json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    echo (!isset($expected[$name])?'UNVERIFIED':($passed?'PASS':'FAIL')).' '.$name.' total='.($page['total']['amount']??'missing').' http='.$status.PHP_EOL;
}
$report=['tested_at'=>date(DATE_ATOM),'url'=>$url,'records'=>$records,'passed'=>count(array_filter($records,fn($r)=>$r['passed'])),'files'=>count($records)];
file_put_contents($output.'/results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
echo $report['passed'].'/'.$report['files']." total/field checks passed.\n";
exit($report['files']===count($expected)&&$report['passed']===$report['files']?0:1);
