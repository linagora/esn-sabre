<?php

namespace ESN\DAVACL;

use Sabre\DAV;

#[\AllowDynamicProperties]
class Plugin extends \ESN\JSON\BasePlugin {
    private const REPORT_READ_PRIVILEGES = [
        '{DAV:}sync-collection' => '{DAV:}read',
        '{urn:ietf:params:xml:ns:caldav}calendar-query' => '{DAV:}read',
        '{urn:ietf:params:xml:ns:caldav}calendar-multiget' => '{DAV:}read',
        '{urn:ietf:params:xml:ns:caldav}free-busy-query' => '{urn:ietf:params:xml:ns:caldav}read-free-busy',
        '{urn:ietf:params:xml:ns:carddav}addressbook-query' => '{DAV:}read',
        '{urn:ietf:params:xml:ns:carddav}addressbook-multiget' => '{DAV:}read',
    ];

    function initialize(DAV\Server $server) {
        parent::initialize($server);

        $server->on('beforeMethod:PROPFIND', [$this, 'beforePropFind'], 20);
        $server->on('report', [$this, 'beforeReport'], 20);
        $server->on('method:PROPFIND', [$this, 'propFind'], 80);
    }

    public function beforePropFind($request, $response) {
        $path = $request->getPath();
        if (!$this->server->tree->nodeExists($path)) {
            return true;
        }

        $aclPlugin = $this->server->getPlugin('acl');
        if ($aclPlugin) {
            // Sabre's propFind marks unreadable properties as 403 but still emits child hrefs.
            $aclPlugin->checkPrivileges($path, '{DAV:}read', \Sabre\DAVACL\Plugin::R_PARENT);
        }

        return true;
    }

    public function beforeReport($reportName, $report, $path) {
        if (!isset(self::REPORT_READ_PRIVILEGES[$reportName])) {
            return true;
        }

        $paths = isset($report->hrefs) ? $report->hrefs : [$path];
        foreach ($paths as $reportPath) {
            $this->assertCanReadExistingPath($reportPath, self::REPORT_READ_PRIVILEGES[$reportName]);
        }

        return true;
    }

    private function assertCanReadExistingPath($path, $privilege) {
        if (!$this->server->tree->nodeExists($path)) {
            return;
        }

        $aclPlugin = $this->server->getPlugin('acl');
        if ($aclPlugin) {
            // Some REPORT handlers synthesize responses directly, bypassing propFind ACL checks.
            $aclPlugin->checkPrivileges($path, $privilege, \Sabre\DAVACL\Plugin::R_PARENT);
        }
    }

    public function propFind($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        /* Adding principal properties */
        if ($node instanceof \Sabre\DAVACL\Principal) {
            $groupMemberSet = $node->getGroupMemberSet();
            foreach ($groupMemberSet as $k => $member) {
                $groupMemberSet[$k] = rtrim($member, '/').'/';
            }

            $groupMemberShip = $node->getGroupMembership();
            foreach ($groupMemberShip as $k => $member) {
                $groupMemberShip[$k] = rtrim($member, '/').'/';
            }

            $body = [
              'alternate-URI-set' => $node->getAlternateUriSet(),
              'principal-URL' => $node->getPrincipalUrl().'/',
              'group-member-set' => $groupMemberSet,
              'group-membership' => $groupMemberShip
            ];

            $this->send(200, $body);
            return false;
        }

        return true;
    }

    function send($code, $body = null, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');

        if ($body) {
            $this->server->httpResponse->setBody(json_encode($body));
        }

        $this->server->httpResponse->setStatus($code);
        return false;
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'davacl-json';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds JSON support for DAVACL',
        ];
    }
}