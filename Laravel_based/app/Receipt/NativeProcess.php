<?php

namespace App\Receipt;

use Symfony\Component\Process\Process;

final class NativeProcess
{
    public function run(array $arguments, ?int $timeout = null): string
    {
        // Array arguments bypass the shell, including paths containing quotes or spaces.
        $nativePath='/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:'.(getenv('PATH')?:'');
        $process = new Process($arguments, null, ['PATH'=>$nativePath,'OMP_THREAD_LIMIT'=>'2', 'MAGICK_THREAD_LIMIT'=>'2', 'MAGICK_MEMORY_LIMIT'=>'128MiB', 'MAGICK_MAP_LIMIT'=>'256MiB', 'MAGICK_DISK_LIMIT'=>'512MiB']);
        $process->setTimeout($timeout ?? config('receipt.timeout'));
        $process->mustRun();
        return $process->getOutput();
    }
}
