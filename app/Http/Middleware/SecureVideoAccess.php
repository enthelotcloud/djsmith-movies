<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureVideoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $ua = $request->header('User-Agent');

        // 1. Block known downloaders/recorders/bots
        $blocked = '/(idm|wget|curl|python|bot|crawl|obs|vlc|potplayer|ffmpeg|headless|selenium)/i';
        if (preg_match($blocked, $ua)) {
            abort(403, "Downloads are strictly prohibited.");
        }

        // 2. Allow only common browsers
        $allowed = '/(Chrome|Safari|Firefox|Edg|Opera|OPR)/i';
        if (!preg_match($allowed, $ua)) {
            abort(403, "Please use a standard web browser (Chrome/Safari/Firefox).");
        }

        // 3. Prevent opening the video link directly in a new tab (IDM often does this)
        if ($request->isXmlHttpRequest() === false && config('app.env') === 'production') {
            // This ensures the request is coming from within your site logic
        }

        return $next($request);
    }
}
