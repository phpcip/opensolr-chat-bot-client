<?php

declare(strict_types=1);

namespace Opensolr\ChatBot;

final class OpensolrApi
{
    public const API_BASE = 'https://opensolr.com/solr_manager/api';
    public const API_BASE_AI = 'https://api.opensolr.com/solr_manager/api';
    public const STALL_SECONDS = 20;

    private const AI_ENDPOINTS = [
        'vdb_info', 'vdb_search', 'local_time', 'currency_rates', 'vat_rates', 'vat_check', 'geo_distance',
        'address_geo', 'postal_codes', 'ip_geo', 'enrich_text', 'chat_completions', 'assistant_chat',
    ];
    private const BODY_MAX = 65536;
    private const LINE_MAX = 1048576;

    public function __construct(
        private readonly string $email,
        private readonly string $apiKey,
        private readonly string $indexName,
    ) {
    }

    public function indexName(): string
    {
        return $this->indexName;
    }

    /**
     * One call of an AI endpoint with the account and the parameters as a form, decoded.
     *
     * @param array<string, scalar> $params
     * @return array<mixed>
     */
    public function call(string $endpoint, array $params = [], int $timeout = 60): array
    {
        $fields = ['email' => $this->email, 'api_key' => $this->apiKey] + $params;
        return $this->post($this->aiUrl($endpoint), http_build_query($fields), 'application/x-www-form-urlencoded', $timeout);
    }

    /**
     * One chat completion (OpenAI request shape) by the Opensolr chat model, as a JSON body.
     *
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    public function chat(array $body, int $timeout = 300): array
    {
        return $this->post($this->aiUrl('chat_completions'), $this->jsonBody($body), 'application/json', $timeout);
    }

    /**
     * The indexes of the account: list of {index_name, index_type}.
     *
     * @return list<array{index_name: string, index_type: string}>
     */
    public function indexList(): array
    {
        if ($this->email === '' || $this->apiKey === '') {
            throw new \RuntimeException('The email and the API key are required.');
        }
        $fields = http_build_query(['email' => $this->email, 'api_key' => $this->apiKey]);
        $data = $this->post(self::API_BASE . '/get_index_list', $fields, 'application/x-www-form-urlencoded', 20);
        if (!array_is_list($data)) {
            throw new \RuntimeException('Unexpected answer from the Opensolr API.');
        }
        $indexes = [];
        foreach ($data as $item) {
            $name = is_array($item) && is_string($item['index_name'] ?? null) ? $item['index_name'] : '';
            if (preg_match(Settings::INDEX_NAME_RE, $name)) {
                $type = is_scalar($item['index_type'] ?? null) ? (string) $item['index_type'] : '';
                $indexes[] = ['index_name' => $name, 'index_type' => mb_substr($type, 0, 60)];
            }
        }
        return $indexes;
    }

    /**
     * A streamed call: each Server-Sent Event's JSON is given to $onEvent as it arrives ($onEvent returns false to
     * stop). Throws ERROR_STREAM_STALLED after STALL_SECONDS without a byte, ERROR_STREAM_TIMEOUT after $timeout.
     *
     * @param array<string, mixed> $body
     * @param callable(array<mixed>): bool $onEvent
     */
    public function stream(string $endpoint, array $body, callable $onEvent, int $timeout): void
    {
        $url = $this->aiUrl($endpoint);
        $state = ['status' => 0, 'type' => '', 'buffer' => '', 'body' => '', 'stopped' => false, 'reason' => null, 'error' => null];
        $started = microtime(true);
        $last = $started;
        $handleLine = function (string $line) use (&$state, $onEvent): void {
            $line = trim($line);
            if (!str_starts_with($line, 'data:')) {
                return;
            }
            $data = trim(substr($line, 5));
            if ($data === '[DONE]') {
                $state['stopped'] = true;
                return;
            }
            $event = json_decode($data, true);
            if (is_array($event) && $onEvent($event) === false) {
                $state['stopped'] = true;
            }
        };
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->options($timeout + 5) + [
            CURLOPT_POSTFIELDS => $this->jsonBody($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/event-stream', 'Expect:'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$state, &$last): int {
                $last = microtime(true);
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
                    $state['status'] = (int) $m[1];
                    $state['type'] = '';
                } elseif (stripos($header, 'content-type:') === 0) {
                    $state['type'] = strtolower(trim(substr($header, 13)));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$state, &$last, $handleLine): int {
                $last = microtime(true);
                if ($state['status'] !== 200 || !str_contains($state['type'], 'text/event-stream')) {
                    if (strlen($state['body']) < self::BODY_MAX) {
                        $state['body'] .= $chunk;
                    }
                    return strlen($chunk);
                }
                try {
                    $state['buffer'] .= $chunk;
                    while (!$state['stopped'] && ($end = strpos($state['buffer'], "\n")) !== false) {
                        $line = substr($state['buffer'], 0, $end);
                        $state['buffer'] = (string) substr($state['buffer'], $end + 1);
                        $handleLine($line);
                    }
                    if (strlen($state['buffer']) > self::LINE_MAX) {
                        throw new \RuntimeException('An event of the Opensolr API stream is too long.');
                    }
                } catch (\Throwable $e) {
                    $state['error'] = $e;
                    return 0;
                }
                return $state['stopped'] ? 0 : strlen($chunk);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function () use (&$state, &$last, $started, $timeout): int {
                $now = microtime(true);
                if ($now - $started > $timeout) {
                    $state['reason'] = 'ERROR_STREAM_TIMEOUT';
                    return 1;
                }
                if ($now - $last > self::STALL_SECONDS) {
                    $state['reason'] = 'ERROR_STREAM_STALLED';
                    return 1;
                }
                return 0;
            },
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch);
        if ($state['error'] instanceof \Throwable) {
            throw $state['error'] instanceof \RuntimeException ? $state['error'] : new \RuntimeException($state['error']->getMessage());
        }
        if ($state['stopped']) {
            return;
        }
        if ($state['reason'] !== null) {
            throw new \RuntimeException($state['reason']);
        }
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new \RuntimeException('ERROR_STREAM_TIMEOUT');
        }
        if ($errno !== 0) {
            throw new \RuntimeException('The Opensolr API did not answer: ' . $error);
        }
        if ($state['status'] !== 200 || !str_contains($state['type'], 'text/event-stream')) {
            $this->decode($state['status'], $state['body']);
            throw new \RuntimeException('HTTP ' . $state['status'] . ' from the Opensolr API.');
        }
        if ($state['buffer'] !== '') {
            $handleLine($state['buffer']);
        }
    }

    private function aiUrl(string $endpoint): string
    {
        if ($this->email === '' || $this->apiKey === '' || $this->indexName === '') {
            throw new \RuntimeException('The Opensolr account or the index is not set up.');
        }
        if (!in_array($endpoint, self::AI_ENDPOINTS, true)) {
            throw new \RuntimeException('Not an endpoint of the Opensolr API servers: ' . $endpoint);
        }
        return self::API_BASE_AI . '/' . $endpoint;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonBody(array $body): string
    {
        $body = ['email' => $this->email, 'api_key' => $this->apiKey, 'index_name' => $this->indexName] + $body;
        return (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<int, mixed>
     */
    private function options(int $timeout): array
    {
        return [
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Opensolr Chat Bot Client',
        ];
    }

    /**
     * @return array<mixed>
     */
    private function post(string $url, string $body, string $contentType, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->options($timeout) + [
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: ' . $contentType, 'Accept: application/json', 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);
        if (!is_string($response)) {
            throw new \RuntimeException('The Opensolr API did not answer: ' . ($error !== '' ? $error : 'no answer'));
        }
        return $this->decode($status, $response);
    }

    /**
     * An answer decoded; a refusal (status false, ERROR) as a RuntimeException with its code.
     *
     * @return array<mixed>
     */
    private function decode(int $status, string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('HTTP ' . $status . ' from the Opensolr API.');
        }
        if ((array_key_exists('status', $data) && $data['status'] === false) || (isset($data['ERROR']) && is_string($data['ERROR']))) {
            $msg = $data['msg'] ?? null;
            $message = is_scalar($msg) ? (string) $msg : (is_string($data['ERROR'] ?? null) ? $data['ERROR'] : 'Refused');
            if (isset($data['detail']) && is_string($data['detail']) && $data['detail'] !== '') {
                $message .= ': ' . $data['detail'];
            }
            throw new \RuntimeException($message);
        }
        if ($status !== 200) {
            throw new \RuntimeException('HTTP ' . $status . ' from the Opensolr API.');
        }
        return $data;
    }
}
