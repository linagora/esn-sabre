<?php

namespace ESN\CardDAV;

use ESN\CardDAV\Group\DomainMembersAccessPolicy;
use ESN\CardDAV\Group\GroupAddressBook;
use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Controls the access to the domain-members address book of a domain.
 *
 * - The technical token of a domain sets the access policy of its domain-members address book:
 *   POST /addressbooks/{domainId}/domain-members.json { "dav:domain-members-access": { "policy": "restricted" } }
 *   'restricted' leaves the address book to the technical token alone, 'readable' gives the domain members (and
 *   administrators) their rights back. The cards are kept: only the ACL of the address book changes.
 * - A technical token never reaches the domain-members address book of another domain: the technical user bypasses
 *   the ACL, so it is checked on the request path, on the hrefs of a REPORT and on the bound / unbound paths.
 */
#[\AllowDynamicProperties]
class DomainMembersAccessPlugin extends \ESN\JSON\BasePlugin {
    const JSON_KEY = 'dav:domain-members-access';
    private const DOMAIN_PRINCIPAL_PREFIX = 'principals/domains/';

    private ?AuthTenant $authTenant = null;

    function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);

        // After the .json extension is removed (15), before the ACL checks (20)
        $server->on('beforeMethod:*', [$this, 'checkRequestPathScope'], 16);
        $server->on('report', [$this, 'checkReportHrefsScope'], 19);
        // COPY / MOVE destinations
        $server->on('beforeBind', [$this, 'checkTechnicalDomainScope']);
        $server->on('beforeUnbind', [$this, 'checkTechnicalDomainScope']);
        // Before the sharing plugin, which handles the other JSON POST bodies of an address book
        $server->on('method:POST', [$this, 'httpPost'], 90);
        $server->on('auth:success', function(AuthTenant $authTenant) {
            $this->authTenant = $authTenant;
        });
    }

    function getPluginName() {
        return 'carddav-domain-members-access';
    }

    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Controls the access to the domain-members address book',
        ];
    }

    protected function getSupportedHeaders() {
        return ['application/json', 'application/vcard+json'];
    }

    function checkRequestPathScope(RequestInterface $request, ResponseInterface $response) {
        $this->checkTechnicalDomainScope($request->getPath());

        return true;
    }

    function checkReportHrefsScope($reportName, $report, $path) {
        $hrefs = isset($report->hrefs) ? $report->hrefs : [];
        array_map(
            fn($href) => $this->checkTechnicalDomainScope($this->server->calculateUri($href)),
            $hrefs
        );

        return true;
    }

    function checkTechnicalDomainScope($path) {
        $domainId = DomainMembersPath::domainIdOf($path);

        if ($domainId !== null && $this->isTechnical() && $domainId !== $this->authTenant->domainId) {
            throw new Forbidden('A technical token only reaches the domain-members address book of its own domain');
        }

        return true;
    }

    function httpPost(RequestInterface $request, ResponseInterface $response) {
        if (!$this->acceptJson() || !DomainMembersPath::matches($request->getPath())) {
            return true;
        }

        $body = $request->getBodyAsString();
        $jsonData = json_decode($body);

        if (!is_object($jsonData) || !property_exists($jsonData, self::JSON_KEY)) {
            // The body can only be read once: give it back to the other POST handlers
            $request->setBody($body);
            return true;
        }

        $node = $this->domainMembersAddressBook($request->getPath());
        $this->assertTechnicalTokenOf($node);
        $node->setAccessPolicy($this->policyOf($jsonData->{self::JSON_KEY}));

        return $this->send(204, null);
    }

    private function domainMembersAddressBook($path): GroupAddressBook {
        $node = $this->server->tree->getNodeForPath($path);

        if (!($node instanceof GroupAddressBook) || !$node->isDomainMembers()) {
            throw new MethodNotAllowed('The access policy can only be set on the domain-members address book');
        }

        return $node;
    }

    private function assertTechnicalTokenOf(GroupAddressBook $node) {
        $domainPrincipal = self::DOMAIN_PRINCIPAL_PREFIX . $this->authTenant?->domainId;

        if (!$this->isTechnical() || $node->getOwner() !== $domainPrincipal) {
            throw new Forbidden('Only a technical token of the domain can set the access policy of its domain-members address book');
        }
    }

    private function policyOf($data): DomainMembersAccessPolicy {
        $value = is_object($data) ? ($data->policy ?? null) : null;
        $policy = is_string($value) ? DomainMembersAccessPolicy::tryFrom($value) : null;

        if ($policy === null) {
            $supportedPolicies = array_map(fn($case) => $case->value, DomainMembersAccessPolicy::cases());
            throw new BadRequest('policy must be one of ' . implode(',', $supportedPolicies));
        }

        return $policy;
    }

    private function isTechnical(): bool {
        return $this->authTenant?->tenantType === TenantType::Technical;
    }
}
