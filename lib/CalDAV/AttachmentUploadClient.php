<?php

namespace ESN\CalDAV;

use Sabre\HTTP\Client;
use Sabre\HTTP\Request;
use Sabre\HTTP\ResponseInterface;

class AttachmentUploadClient {
    private Client $client;

    function __construct(
        private string $endpoint,
        ?Client $client = null
    ) {
        $endpointParts = $this->parseUploadUrl($endpoint);
        $hasQueryOrFragment = !empty($endpointParts['query']) || !empty($endpointParts['fragment']);
        if ($endpointParts === null || $hasQueryOrFragment) {
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

            return $this->parseUploadResponse($response);
        } catch (\Throwable $error) {
            // Do not expose response bodies, credentials or public share codes in DAV errors.
            throw new AttachmentUploadException();
        }
    }

    private function parseUploadResponse(ResponseInterface $response): string {
        if ($response->getStatus() !== 201) {
            throw new \RuntimeException('Attachment service returned HTTP ' . $response->getStatus());
        }

        $result = json_decode($response->getBodyAsString(), true, 32, JSON_THROW_ON_ERROR);
        $uri = $result['uri'] ?? '';

        if (!$this->isValidAttachmentUri($uri)) {
            throw new \RuntimeException('Attachment service returned invalid metadata');
        }

        return $uri;
    }

    private function isValidAttachmentUri(mixed $uri): bool {
        if (!is_string($uri)) {
            return false;
        }

        return $this->parseUploadUrl($uri) !== null && !preg_match('/[\x00-\x20\x7f]/', $uri);
    }

    private function parseUploadUrl(string $url): ?array {
        $parts = parse_url($url);
        $isHttp = in_array($parts['scheme'] ?? '', ['http', 'https'], true);
        $hasHostWithoutUser = !empty($parts['host']) && empty($parts['user']);

        return $isHttp && $hasHostWithoutUser ? $parts : null;
    }
}
