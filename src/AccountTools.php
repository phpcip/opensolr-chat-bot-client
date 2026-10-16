<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

/**
 * The account commands (/account, /index, /ingestion, /errors): offered only when the Opensolr API says this chat's index
 * answers questions about the signed-in visitor's own Opensolr account. They run for that visitor only.
 */
final class AccountTools
{
    public const COMMANDS = ['account' => 'opensolr_account', 'index' => 'opensolr_index', 'ingestion' => 'opensolr_ingestion', 'errors' => 'opensolr_solr_errors'];
    private const CHECK_SECONDS = 3600;
    private const TIMEOUT = 30;

    /** Whether the Opensolr API offers the account tools for this chat's index: asked once an hour, never kept after a failure. */
    public static function available(Store $store, OpensolrApi $api): bool
    {
        $kept = explode(':', (string) $store->meta('account_tools'));
        if (count($kept) === 2 && (int) $kept[1] > time() - self::CHECK_SECONDS) {
            return $kept[0] === '1';
        }
        try {
            $available = !empty($api->account(['tool' => 'available'], self::TIMEOUT)['available']);
        } catch (\Throwable $e) {
            return false;
        }
        $store->setMeta('account_tools', ($available ? '1' : '0') . ':' . time());
        return $available;
    }

    /**
     * One account tool for the signed-in visitor: its data, or the message of why there is none.
     *
     * @param array<string, scalar> $args
     * @return array<mixed>|string
     */
    public static function run(OpensolrApi $api, string $command, string $email, array $args): array|string
    {
        if ($email === '') {
            return 'Sign in to your Opensolr account to use this command: it reads your own account.';
        }
        try {
            $r = $api->account(['tool' => self::COMMANDS[$command], 'visitor_email' => $email, 'args' => $args], self::TIMEOUT);
        } catch (\Throwable $e) {
            return $e->getMessage() === 'ERROR_NOT_SIGNED_IN'
                ? 'Sign in to your Opensolr account to use this command: it reads your own account.'
                : 'Your account could not be read right now. Please try again in a moment.';
        }
        $data = $r['data'] ?? null;
        if (!is_array($data)) {
            return 'Your account could not be read right now. Please try again in a moment.';
        }
        return isset($data['error']) && is_string($data['error']) ? $data['error'] : $data;
    }
}
