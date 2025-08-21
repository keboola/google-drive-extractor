<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Auth;

use GuzzleHttp\Client as GuzzleClient;
use Keboola\GoogleDriveExtractor\Exception\UserException;

final class ServiceAccountTokenFactory
{
    /**
     * @param array{client_email:string, private_key:string, token_uri?:string} $sa
     * @param string[] $scopes
     * @return string access_token
     */
    public function getAccessToken(array $sa, array $scopes): string
    {
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new UserException('Service Account JSON missing client_email or private_key.');
        }
        $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        $now = time();
        $claims = [
            'iss'   => $sa['client_email'],
            'scope' => implode(' ', $scopes),
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600, // 1h
            // 'sub' => 'user@your-domain.com', // (optional) for Domain-Wide Delegation
        ];

        $jwt = $this->signJwt($claims, $sa['private_key']);

        $http = new GuzzleClient(['timeout' => 30, 'http_errors' => false]);
        $resp = $http->post($tokenUri, [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();
        $json = json_decode($body, true);

        if ($code !== 200 || empty($json['access_token'])) {
            $err = $json['error_description'] ?? $json['error'] ?? $body;
            throw new UserException('Failed to obtain access token from Service Account: ' . $err);
        }

        return $json['access_token'];
    }

    /** @param array<string,mixed> $claims */
    private function signJwt(array $claims, string $privateKey): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $segments = [
            $this->b64(json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->b64(json_encode($claims, JSON_UNESCAPED_SLASHES)),
        ];
        $data = implode('.', $segments);

        $pem = $this->normalizePem($privateKey);
        $pkey = openssl_pkey_get_private($pem);
        if ($pkey === false) {
            throw new UserException('Invalid service account private key PEM.');
        }

        $signature = '';
        $ok = openssl_sign($data, $signature, $pkey, OPENSSL_ALGO_SHA256);
        openssl_free_key($pkey);
        if (!$ok) {
            throw new UserException('Failed to sign JWT with service account key.');
        }

        $segments[] = $this->b64($signature);
        return implode('.', $segments);
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function normalizePem(string $pem): string
    {
        if (strpos($pem, '\\n') !== false) {
            $pem = str_replace('\\n', "\n", $pem);
        }
        return $pem;
    }
}
