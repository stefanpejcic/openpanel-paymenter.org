<?php
################################################################################
# Name: OpenPanel paymenter.org Module
# Usage: https://openpanel.com/docs/articles/extensions/openpanel-and-paymenter.org/
# Source: https://github.com/stefanpejcic/openpanel-paymenter.org
# Author: Stefan Pejcic
# Created: 09.10.2024
# Last Modified: 21.05.2025
# Company: openpanel.com
# Copyright (c) Stefan Pejcic
#
# Permission is hereby granted, free of charge, to any person obtaining a copy
# of this software and associated documentation files (the "Software"), to deal
# in the Software without restriction, including without limitation the rights
# to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
# copies of the Software, and to permit persons to whom the Software is
# furnished to do so, subject to the following conditions:
#
# The above copyright notice and this permission notice shall be included in
# all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
# IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
# FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
# AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
# LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
# OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
# THE SOFTWARE.
################################################################################

namespace App\Extensions\Servers\OpenPanel;

use App\Classes\Extensions\Server;
use App\Helpers\ExtensionHelper;
use Illuminate\Support\Facades\Http;

class OpenPanel extends Server
{
    public function getMetadata()
    {
        return [
            'display_name' => 'OpenPanel',
            'version' => '1.1.0',
            'author' => 'Paymenter',
            'website' => 'https://paymenter.org',
        ];
    }

    public function getConfig()
    {
        return [
            [
                'name' => 'host',
                'friendlyName' => 'URL to OpenPanel server (hostname or IP, without port)',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'port',
                'friendlyName' => 'Port (default: 2087)',
                'type' => 'text',
                'required' => false,
            ],
            [
                'name' => 'username',
                'friendlyName' => 'Admin Username',
                'type' => 'text',
                'required' => true,
            ],
            [
                'name' => 'password',
                'friendlyName' => 'Admin Password',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function getProductConfig($options)
    {
        return [
            [
                'name' => 'packageName',
                'friendlyName' => 'Package Name',
                'type' => 'text',
                'required' => true,
                'description' => 'Package/plan name as configured on the OpenPanel server',
            ],
        ];
    }

    public function getUserConfig()
    {
        return [
            [
                'name' => 'domain',
                'friendlyName' => 'Domain',
                'type' => 'text',
                'required' => true,
                'description' => 'Primary domain for the hosting account',
            ],
            [
                'name' => 'username',
                'friendlyName' => 'Username',
                'type' => 'text',
                'required' => true,
                'description' => 'Username for the OpenPanel account',
            ],
            [
                'name' => 'password',
                'friendlyName' => 'Password',
                'type' => 'text',
                'required' => true,
                'description' => 'Password for the OpenPanel account',
            ],
        ];
    }


    public function createServer($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        // 1. Create the user account
        $response = $this->apiRequest($params, $jwtToken, 'POST', '/api/users', [
            'username'  => $params['config']['username'],
            'password'  => $params['config']['password'],
            'email'     => $user->email,
            'plan_name' => $params['packageName'],
        ]);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to create user: ' . ($response['error'] ?? json_encode($response)));
            return;
        }

        // 2. Add the domain if provided
        if (!empty($params['config']['domain'])) {
            $domainResponse = $this->apiRequest($params, $jwtToken, 'POST', '/api/domains/new', [
                'username' => $params['config']['username'],
                'domain'   => $params['config']['domain'],
                'docroot'  => '/var/www/html/' . $params['config']['domain'],
            ]);

            if (isset($domainResponse['error'])) {
                ExtensionHelper::error('OpenPanel', 'User created, but failed to add domain: ' . $domainResponse['error']);
            }
        }
    }

    public function suspendServer($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        $response = $this->apiRequest($params, $jwtToken, 'PATCH', '/api/users/' . $params['config']['username'], [
            'action' => 'suspend',
        ]);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to suspend server: ' . ($response['error'] ?? json_encode($response)));
        }
    }

    public function unsuspendServer($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        $response = $this->apiRequest($params, $jwtToken, 'PATCH', '/api/users/' . $params['config']['username'], [
            'action' => 'unsuspend',
        ]);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to unsuspend server: ' . ($response['error'] ?? json_encode($response)));
        }
    }

    public function terminateServer($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        // Unsuspend first (ensures account is active before deletion)
        $this->apiRequest($params, $jwtToken, 'PATCH', '/api/users/' . $params['config']['username'], [
            'action' => 'unsuspend',
        ]);

        $response = $this->apiRequest($params, $jwtToken, 'DELETE', '/api/users/' . $params['config']['username']);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to terminate server: ' . ($response['error'] ?? json_encode($response)));
        }
    }

    public function changePackage($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        $response = $this->apiRequest($params, $jwtToken, 'PUT', '/api/users/' . $params['config']['username'], [
            'plan_name' => $params['packageName'],
        ]);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to change package: ' . ($response['error'] ?? json_encode($response)));
        }
    }

    public function changePassword($user, $params, $order, $product, $configurableOptions)
    {
        [$jwtToken, $error] = $this->getAuthToken($params);
        if (!$jwtToken) {
            ExtensionHelper::error('OpenPanel', 'Failed to get authentication token: ' . $error);
            return;
        }

        $response = $this->apiRequest($params, $jwtToken, 'PATCH', '/api/users/' . $params['config']['username'], [
            'password' => $params['config']['password'],
        ]);

        if (!isset($response['success']) || !$response['success']) {
            ExtensionHelper::error('OpenPanel', 'Failed to change password: ' . ($response['error'] ?? json_encode($response)));
        }
    }

    /**
     * Returns the base URL for the API, using https for hostnames and http for raw IP addresses
     */
    private function getBaseUrl(array $params): string
    {
        $host     = ExtensionHelper::getConfig('OpenPanel', 'host');
        $port     = ExtensionHelper::getConfig('OpenPanel', 'port') ?: 2087;
        $protocol = filter_var($host, FILTER_VALIDATE_IP) !== false ? 'http://' : 'https://';

        return $protocol . $host . ':' . $port;
    }

    /**
     * Authenticates with the OpenAdmin API and returns [token, error].
     */
    private function getAuthToken(array $params = []): array
    {
        $authEndpoint = $this->getBaseUrl($params) . '/api/';

        $response = Http::post($authEndpoint, [
            'username' => ExtensionHelper::getConfig('OpenPanel', 'username'),
            'password' => ExtensionHelper::getConfig('OpenPanel', 'password'),
        ]);

        if (!$response->successful()) {
            return [false, 'HTTP ' . $response->status() . ': ' . $response->body()];
        }

        $data = $response->json();

        if (!isset($data['access_token'])) {
            return [false, 'Token not found in response: ' . $response->body()];
        }

        return [$data['access_token'], null];
    }

    /**
     * Executes an authenticated API request and returns the decoded JSON body.
     */
    private function apiRequest(array $params, string $token, string $method, string $uri, array $data = []): array
    {
        $url = $this->getBaseUrl($params) . $uri;

        $request = Http::withToken($token);

        $response = match (strtoupper($method)) {
            'GET'    => $request->get($url),
            'POST'   => $request->post($url, $data),
            'PATCH'  => $request->patch($url, $data),
            'PUT'    => $request->put($url, $data),
            'DELETE' => $request->delete($url),
            default  => $request->post($url, $data),
        };

        return $response->json() ?? [];
    }
}
