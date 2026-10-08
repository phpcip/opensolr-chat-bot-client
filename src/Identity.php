<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The signed-in visitor: the site writes the email and its signature on the widget's script tag, and only a
 * signature made with this chat's identity key makes the visitor signed in.
 */
final class Identity
{
    private const EMAIL_MAX = 254;

    /** The identity key of the chat (derived from its signing secret), for sites that sign in another language. */
    public static function key(Store $store): string
    {
        return $store->key('ident');
    }

    /** The email as it is signed and kept: trimmed, lower-case; '' when it is not a valid email address. */
    public static function email(string $email): string
    {
        $email = strtolower(trim($email));
        return $email !== '' && strlen($email) <= self::EMAIL_MAX && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /** The signature of an email: HMAC-SHA256 of the lower-case email with the identity key, in hex. */
    public static function sign(Store $store, string $email): string
    {
        return hash_hmac('sha256', self::email($email), self::key($store));
    }

    /** The email of the signed-in visitor, '' when the email or its signature is not valid. */
    public static function verify(Store $store, string $email, string $signature): string
    {
        $email = self::email($email);
        if ($email === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return '';
        }
        return hash_equals(self::sign($store, $email), $signature) ? $email : '';
    }

    /**
     * The attributes for the widget's script tag of a signed-in visitor (data-ident and data-ident-sig), escaped;
     * '' for a visitor who is not signed in.
     */
    public static function attributes(string $dataDir, string $email): string
    {
        $email = self::email($email);
        $store = new Store($dataDir);
        // Never creates the data folder: a box without the chat writes no identity
        if ($email === '' || !is_file($store->file())) {
            return '';
        }
        // A database that cannot be read leaves the visitor anonymous, never breaks the site's page
        try {
            $sign = self::sign($store, $email);
        } catch (\Throwable $e) {
            error_log('Opensolr Chat Bot: the identity of a signed-in visitor could not be signed: ' . $e->getMessage());
            return '';
        }
        return ' data-ident="' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '" data-ident-sig="' . $sign . '"';
    }
}
