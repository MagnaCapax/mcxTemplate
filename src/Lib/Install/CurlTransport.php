<?php
declare(strict_types=1);

namespace Lib\Install;

// CurlTransport is the production HttpTransport (php-curl). Every request is
// HTTPS-only with the peer certificate and host name verified against the system
// CA bundle, no redirects (an https URL must not bounce to another protocol or
// host), TLS 1.2 or newer, and hard connect and total timeouts. Errors are
// reduced to a short code: curl's own messages can carry the URL.
final class CurlTransport implements HttpTransport
{
    public const MAX_BODY = 65536;

    private int $connectTimeout;
    private int $postTimeout;
    private int $downloadTimeout;

    public function __construct(int $connectTimeout = 10, int $postTimeout = 30, int $downloadTimeout = 3600)
    {
        $this->connectTimeout = $connectTimeout;
        $this->postTimeout = $postTimeout;
        $this->downloadTimeout = $downloadTimeout;
    }

    public function post(string $url, string $json): array
    {
        $body = '';
        $tooLarge = false;
        $curl = $this->handle($url, $this->postTimeout);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
        // The sink aborts the transfer (returning a short count) once the cap is passed.
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($c, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > self::MAX_BODY) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        });
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);

        if ($tooLarge) {
            return ['status' => 0, 'body' => '', 'error' => 'bodyTooLarge'];
        }
        if ($ok === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl' . $errno];
        }
        return ['status' => $status, 'body' => $body, 'error' => ''];
    }

    public function download(string $url, string $dest, int $maxBytes): array
    {
        $out = @fopen($dest, 'xb');
        if ($out === false) {
            return ['status' => 0, 'bytes' => 0, 'error' => 'destExists'];
        }
        $written = 0;
        $tooLarge = false;
        $curl = $this->handle($url, $this->downloadTimeout);
        curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($c, string $chunk) use ($out, &$written, &$tooLarge, $maxBytes): int {
            if ($written + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $n = fwrite($out, $chunk);
            if ($n === false) {
                return 0;
            }
            $written += $n;
            return $n;
        });
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        $closed = fclose($out);

        $error = $tooLarge ? 'bodyTooLarge' : ($ok === false ? 'curl' . $errno : ($status !== 200 ? 'http' . $status : ($closed ? '' : 'writeFailed')));
        if ($error !== '') {
            @unlink($dest);
            return ['status' => $status, 'bytes' => 0, 'error' => $error];
        }
        return ['status' => $status, 'bytes' => $written, 'error' => ''];
    }

    // handle builds a curl handle with the fixed, strict option set shared by both calls.
    private function handle(string $url, int $totalTimeout)
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new InstallRefusal('nodeCoreUnreachable', 'curl could not be initialised');
        }
        curl_setopt_array($curl, [
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $totalTimeout,
            CURLOPT_USERAGENT => 'mcxInstall',
            CURLOPT_NOSIGNAL => true,
        ]);
        return $curl;
    }
}
