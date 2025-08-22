<?php

declare(strict_types=1);

namespace Keboola\GoogleDriveExtractor\Auth;

use RuntimeException;

/**
 * Builds a Google Service Account JWT and exchanges it for an access token.
 */
class ServiceAccountTokenFactory
{
    /**
     * @param array{client_email:string, private_key:string} $serviceAccount
     * @param list<string>                                   $scopes
     */
    public function getAccessToken(array $serviceAccount, array $scopes): string
    {
        $now = time();

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'iss' => $serviceAccount['client_email'],
            'scope' => implode(' ', $scopes),
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ];

        // json_encode() must return string (never false) → throws on error
        $headerJson  = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $signingInput = $this->b64($headerJson) . '.' . $this->b64($payloadJson);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $serviceAccount['private_key'], OPENSSL_ALGO_SHA256);
        if ($ok !== true) {
            throw new RuntimeException('Failed to sign service account JWT.');
        }

        $jwt = $signingInput . '.' . $this->b64($signature);

        // Exchange the assertion for an access token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ], '', '&');

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 30,
        ]);

        /** @var string|false $resp */
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Google token exchange failed: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        /** @var array<string,mixed> $json */
        $json = json_decode($resp, true);
        if ($code >= 400 || !is_array($json) || !isset($json['access_token']) || !is_string($json['access_token'])) {
            throw new RuntimeException('Google token exchange error: ' . $resp);
        }

        return $json['access_token'];
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
