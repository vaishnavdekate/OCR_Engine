<?php

namespace App\Http\Controllers;

use App\Receipt\InvalidDocument;
use App\Receipt\ReceiptEngine;
use App\Receipt\ReceiptLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class ReceiptController extends Controller
{
    public function health(ReceiptLock $lock)
    {
        return response()->json(['status'=>'ok','engine'=>ReceiptEngine::NAME,'busy'=>$lock->busy(),'authentication_required'=>(string)config('receipt.token')!=='']);
    }

    public function upload(Request $request,ReceiptEngine $engine,ReceiptLock $lock)
    {
        $file=$request->file('file');
        if (!$file || !$file->isValid()) return response()->json(['detail'=>'A valid receipt file is required.'],422);
        if ($file->getSize()>config('receipt.max_bytes')) return response()->json(['detail'=>'File exceeds the 20 MiB limit.'],413);
        $currency=$request->input('currency');
        if ($currency!==null) {
            if (!is_string($currency)) return response()->json(['detail'=>'Currency must be a three-letter code.'],422);
            $currency=strtoupper(trim($currency))?:null;
            if ($currency && !preg_match('~^[A-Z]{3}$~D',$currency)) return response()->json(['detail'=>'Currency must be a three-letter code, such as CHF.'],422);
        }
        $preview=filter_var($request->input('preview',true),FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE);
        if ($preview===null) return response()->json(['detail'=>'Preview must be a boolean.'],422);
        $handle=$lock->acquire();
        if (!$handle) return response()->json(['detail'=>'OCR is busy. Try again shortly.'],503,['Retry-After'=>'5']);
        try {
            $data=file_get_contents($file->getRealPath());
            return response()->json($engine->process($data,$file->getClientOriginalName(),$currency,$preview));
        } catch (InvalidDocument $e) { return response()->json(['detail'=>$e->getMessage()],422); }
        catch (\Throwable $e) { Log::error('Receipt OCR failed',['exception'=>$e::class,'message'=>$e->getMessage()]); return response()->json(['detail'=>'OCR could not finish. Check server logs and retry.'],500); }
        finally { $lock->release($handle); }
    }
}
