<?php

namespace App\Console\Commands;

use App\Receipt\ReceiptEngine;
use App\Receipt\ReceiptLock;
use Illuminate\Console\Command;

final class ExtractReceipts extends Command
{
    protected $signature='receipt:extract {files* : Receipt paths} {--currency= : Three-letter currency hint} {--output= : JSON output path}';
    protected $description='Extract receipt amounts locally, using PHP and native OCR tools';

    public function handle(ReceiptEngine $engine,ReceiptLock $lock): int
    {
        $currency=$this->option('currency')?strtoupper(trim($this->option('currency'))):null;
        if ($currency && !preg_match('~^[A-Z]{3}$~D',$currency)) { $this->error('Currency must be a three-letter code.'); return 2; }
        $handle=$lock->acquire(); if (!$handle) { $this->error('OCR is busy. Try again shortly.'); return 2; }
        try {
            $results=[];
            foreach ($this->argument('files') as $path) {
                if (!is_file($path) || !is_readable($path)) throw new \InvalidArgumentException('Cannot read receipt: '.$path);
                if (filesize($path)>config('receipt.max_bytes')) throw new \InvalidArgumentException('File exceeds the 20 MiB limit.');
                $results[]=$engine->process(file_get_contents($path),basename($path),$currency,false);
            }
            $json=json_encode(count($results)===1?$results[0]:$results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            if ($this->option('output')) {
                if (file_put_contents($this->option('output'),$json)===false) throw new \RuntimeException('Cannot write output file.');
            } else $this->output->write($json);
            return 0;
        } catch (\Throwable $e) { $this->error($e->getMessage()); return 2; }
        finally { $lock->release($handle); }
    }
}
