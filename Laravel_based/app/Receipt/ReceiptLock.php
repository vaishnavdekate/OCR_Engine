<?php

namespace App\Receipt;

final class ReceiptLock
{
    public function acquire()
    {
        $path=storage_path('app/receipt.lock');
        if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
        $handle=fopen($path,'c+');
        if (!$handle || !flock($handle,LOCK_EX|LOCK_NB)) { if ($handle) fclose($handle); return null; }
        return $handle;
    }

    public function release($handle): void { flock($handle,LOCK_UN); fclose($handle); }

    public function busy(): bool
    {
        $handle=$this->acquire();
        if (!$handle) return true;
        $this->release($handle); return false;
    }
}
