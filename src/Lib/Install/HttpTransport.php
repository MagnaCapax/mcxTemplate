<?php
declare(strict_types=1);

namespace Lib\Install;

// HttpTransport is the only way the network install talks HTTPS. NodeCoreClient and
// TemplateFetcher depend on this interface, so tests use a recorded fake and no test
// ever opens a socket. CurlTransport is the production implementation.
interface HttpTransport
{
    /**
     * POST a JSON body. The response body is capped by the implementation.
     *
     * @return array{status:int,body:string,error:string} status 0 means "no HTTP answer" (network error,
     *         timeout, oversized body); error is then a short code, never a URL or a body.
     */
    public function post(string $url, string $json): array;

    /**
     * GET $url into the NEW file $dest, refusing more than $maxBytes. No redirects are followed.
     *
     * @return array{status:int,bytes:int,error:string} the file is removed on any non-200 or error
     */
    public function download(string $url, string $dest, int $maxBytes): array;
}
