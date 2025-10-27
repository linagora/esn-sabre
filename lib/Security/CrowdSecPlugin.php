<?php

namespace ESN\Security;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * CrowdSec Security Plugin for SabreDAV
 *
 * This plugin integrates CrowdSec security filtering into the SabreDAV request pipeline.
 * It checks incoming IP addresses against CrowdSec decisions and blocks banned IPs.
 */
#[\AllowDynamicProperties]
class CrowdSecPlugin extends ServerPlugin {

    /**
     * @var Server
     */
    protected $server;

    /**
     * @var ICrowdSecClient
     */
    private $crowdSecClient;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $banHttpCode;

    /**
     * @param ICrowdSecClient $crowdSecClient CrowdSec client implementation
     * @param LoggerInterface|null $logger Optional logger
     * @param int $banHttpCode HTTP status code to return for banned IPs (default: 403)
     */
    public function __construct(ICrowdSecClient $crowdSecClient, ?LoggerInterface $logger = null, int $banHttpCode = 403) {
        $this->crowdSecClient = $crowdSecClient;
        $this->logger = $logger ?? new NullLogger();
        $this->banHttpCode = $banHttpCode;
    }

    /**
     * Initializes the plugin
     *
     * @param Server $server
     * @return void
     */
    public function initialize(Server $server) {
        $this->server = $server;

        // Hook into the very beginning of request processing
        $this->server->on('beforeMethod:*', [$this, 'beforeMethod'], 10);
    }

    /**
     * This method is triggered before any HTTP method processing
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool|null
     */
    public function beforeMethod(RequestInterface $request, ResponseInterface $response) {
        $ip = $this->getClientIp($request);

        if (!$ip) {
            $this->logger->warning('CrowdSec: Could not determine client IP');
            return null;
        }

        // Check if IP should be blocked
        if ($this->crowdSecClient->shouldBlock($ip)) {
            $this->logger->warning('CrowdSec: Blocked request from banned IP', [
                'ip' => $ip,
                'method' => $request->getMethod(),
                'path' => $request->getPath(),
                'httpCode' => $this->banHttpCode
            ]);

            $response->setStatus($this->banHttpCode);
            $response->setHeader('Content-Type', 'application/json');
            $response->setBody(json_encode([
                'error' => $this->getHttpStatusText($this->banHttpCode),
                'message' => 'Access denied'
            ]));

            // Return false to stop further processing
            return false;
        }

        // Allow request to continue
        return null;
    }

    /**
     * Extract client IP from request headers
     *
     * @param RequestInterface $request
     * @return string|null
     */
    private function getClientIp(RequestInterface $request): ?string {
        // Check common proxy headers first
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP', // Cloudflare
            'True-Client-IP',   // Akamai and Cloudflare
        ];

        foreach ($headers as $header) {
            $value = $request->getHeader($header);
            if ($value) {
                // X-Forwarded-For can contain multiple IPs, take the first one
                $ips = array_map('trim', explode(',', $value));
                if (!empty($ips[0])) {
                    return $ips[0];
                }
            }
        }

        // Fallback to remote address from server variables
        $serverVars = $_SERVER ?? [];

        if (isset($serverVars['REMOTE_ADDR'])) {
            return $serverVars['REMOTE_ADDR'];
        }

        return null;
    }

    /**
     * Get HTTP status text for a given status code
     *
     * @param int $code
     * @return string
     */
    private function getHttpStatusText(int $code): string {
        $texts = [
            403 => 'Forbidden',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable'
        ];
        return $texts[$code] ?? 'Forbidden';
    }

    /**
     * Returns a plugin name.
     *
     * @return string
     */
    public function getPluginName() {
        return 'crowdsec-security';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * @return array
     */
    public function getPluginInfo() {
        return [
            'name' => $this->getPluginName(),
            'description' => 'CrowdSec security filtering for SabreDAV',
            'link' => 'https://www.crowdsec.net/'
        ];
    }
}
