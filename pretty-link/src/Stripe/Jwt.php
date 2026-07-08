<?php

declare(strict_types=1);

namespace PrettyLinks\Stripe;

/**
 * HS256 JWT used by the Caseproof auth/connect services. Matches the
 * v3.x `PrliAuthenticatorController::generate_jwt()` output byte-for-byte
 * so existing site_uuid / secret_token pairs continue to authenticate.
 */
final class Jwt
{
    /**
     * Encodes an HS256 JWT for the Caseproof auth/connect services.
     *
     * @param array<string, mixed> $payload The token payload.
     * @param string|null          $secret  Optional signing secret; defaults to the stored secret token.
     *
     * @return string
     */
    public static function encode(array $payload, ?string $secret = null): string
    {
        if ($secret === null) {
            $secret = (string) get_option('prli_authenticator_secret_token');
        }

        $headerSegment  = self::base64url((string) wp_json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256',
        ]));
        $payloadSegment = self::base64url((string) wp_json_encode($payload));

        $signature = hash_hmac('sha256', "{$headerSegment}.{$payloadSegment}", $secret);
        // V3 quirk: json_encodes the signature (wrapping it in quotes) before base64url.
        // Preserved verbatim for bit-identical output.
        $signatureSegment = self::base64url((string) wp_json_encode($signature));

        return "{$headerSegment}.{$payloadSegment}.{$signatureSegment}";
    }

    /**
     * Builds the HTTP headers for an auth-service request.
     *
     * @param  string $jwt    The encoded JWT.
     * @param  string $domain The target service host.
     * @return array<string, string>
     */
    public static function header(string $jwt, string $domain): array
    {
        return [
            'Authorization' => 'Bearer ' . $jwt,
            'Accept'        => 'application/json;ver=1.0',
            'Content-Type'  => 'application/json; charset=UTF-8',
            'Host'          => $domain,
        ];
    }

    /**
     * Encodes a string using URL-safe base64 without padding.
     *
     * @param  string $value The raw value to encode.
     * @return string
     */
    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
