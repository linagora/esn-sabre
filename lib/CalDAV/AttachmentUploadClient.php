<?php

namespace ESN\CalDAV;

use Sabre\DAV\Exception\ServiceUnavailable;
use Sabre\HTTP\Client;
use Sabre\HTTP\Request;

class AttachmentUploadClient {
    private Client $client;

    function __construct(
        private string $endpoint,
        ?Client $client = null
    ) {
        if (
            !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['http', 'https'], true)
            || !parse_url($endpoint, PHP_URL_HOST)
            || parse_url($endpoint, PHP_URL_USER)
            || parse_url($endpoint, PHP_URL_QUERY)
            || parse_url($endpoint, PHP_URL_FRAGMENT)
        ) {
            throw new \InvalidArgumentException('Attachment upload endpoint must be configured');
        }

        // Never forward the user credential through an upstream redirect.
        $this->client = $client ?? new class extends Client {
            protected $maxRedirects = 0;
        };
        $this->client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, 5);
        $this->client->addCurlSetting(CURLOPT_TIMEOUT, 90);
    }

    function upload(
        string $authorization,
        string $filename,
        string $contentType,
        string $content
    ): string {
        try {
            $response = $this->client->send(new Request(
                'POST',
                $this->endpoint,
                [
                    'Authorization' => $authorization,
                    'Content-Type' => $contentType,
                    'X-Filename' => rawurlencode($filename)
                ],
                $content
            ));

            if ($response->getStatus() !== 201) {
                throw new \RuntimeException('Attachment service returned HTTP ' . $response->getStatus());
            }

            $result = json_decode($response->getBodyAsString(), true, 32, JSON_THROW_ON_ERROR);
            $uri = $result['uri'] ?? '';

            if (
                !is_string($uri)
                || !in_array(parse_url($uri, PHP_URL_SCHEME), ['https', 'http'], true)
                || !parse_url($uri, PHP_URL_HOST)
                || parse_url($uri, PHP_URL_USER)
                || preg_match('/[\x00-\x20\x7f]/', $uri)
            ) {
                throw new \RuntimeException('Attachment service returned invalid metadata');
            }

            return $uri;
        } catch (\Throwable $error) {
            // Do not expose response bodies, credentials or public share codes in DAV errors.
            throw new ServiceUnavailable('Attachment upload failed. Please retry saving the event.');
        }
    }
}
