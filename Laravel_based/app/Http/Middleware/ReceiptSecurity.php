<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class ReceiptSecurity
{
    public function handle(Request $request, Closure $next)
    {
        if (!in_array($request->getHost(),array_map('trim',config('receipt.allowed_hosts')),true)) return response()->json(['detail'=>'Invalid host header.'],400);
        if ($request->isMethod('POST')) {
            if ((int)$request->header('Content-Length',0)>config('receipt.max_bytes')+65536) return response()->json(['detail'=>'Upload exceeds the request size limit.'],413);
            $origin=$request->header('Origin');
            if ($origin && $origin!==$request->getSchemeAndHttpHost()) return response()->json(['detail'=>'Cross-origin uploads are not allowed.'],403);
            $token=(string)config('receipt.token');
            if ($token!=='' && !hash_equals('Bearer '.$token,(string)$request->header('Authorization',''))) return response()->json(['detail'=>'A valid API token is required.'],401,['WWW-Authenticate'=>'Bearer']);
        }
        $response=$next($request);
        $response->headers->set('X-Content-Type-Options','nosniff');
        $response->headers->set('Referrer-Policy','no-referrer');
        $response->headers->set('Cache-Control','no-store');
        $response->headers->set('Content-Security-Policy',"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        return $response;
    }
}
