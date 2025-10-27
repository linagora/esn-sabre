<?php

namespace ESN\Security;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Real CrowdSec client that connects to CrowdSec Local API (LAPI)
 */
#[\AllowDynamicProperties]
class CrowdSecClient implements ICrowdSecClient {

    private $httpClient;
    private $apiUrl;
    private $apiKey;
    private $logger;

    /**
     * @param string $apiUrl CrowdSec LAPI URL (e.g., http://localhost:8080)
     * @param string $apiKey CrowdSec API key
     * @param LoggerInterface|null $logger Optional logger
     */
    public function __construct(string $apiUrl, string $apiKey, ?LoggerInterface $logger = null) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->logger = $logger ?? new NullLogger();

        $this->httpClient = new Client([
            'timeout' => 2.0,
            'connect_timeout' => 1.0,
        ]);
    }

    /**
     * Check if an IP address should be blocked according to CrowdSec
     *
     * @param string $ip IP address to check
     * @return bool true if IP should be blocked, false otherwise
     */
    public function shouldBlock(string $ip): bool {
        try {
            $response = $this->httpClient->get(
                $this->apiUrl . '/v1/decisions',
                [
                    'headers' => [
                        'X-Api-Key' => $this->apiKey,
                    ],
                    'query' => [
                        'ip' => $ip,
                    ]
                ]
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $body = json_decode($response->getBody()->getContents(), true);

                // Check if there are active "ban" decisions for this IP
                if (is_array($body) && count($body) > 0) {
                    foreach ($body as $decision) {
                        // Only consider "ban" type decisions
                        if (isset($decision['type']) && $decision['type'] === 'ban') {
                            $this->logger->info('CrowdSec: blocking IP with ban decision', [
                                'ip' => $ip,
                                'scenario' => $decision['scenario'] ?? 'unknown',
                                'duration' => $decision['duration'] ?? 'unknown'
                            ]);
                            return true;
                        }
                    }
                }
            }

            return false;

        } catch (GuzzleException $e) {
            // On error, fail open (don't block) but log the error
            $this->logger->error('CrowdSec API error', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return false;
        } catch (\Throwable $e) {
            // Catch any other errors
            $this->logger->error('CrowdSec unexpected error', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
