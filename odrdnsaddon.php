<?php

use GuzzleHttp\Client;

require_once 'vendor/autoload.php';

/**
 * -------------------------------------------------------------------------------------
 * odrdnsaddon
 *
 * Author:   Webstack B.V.
 * Copyright:   Webstack B.V.
 * Version:   1.0
 *
 * CHANGE LOG:
 * -------------------------------------------------------------------------------------
 *  yyyy-mm-yy        Author                                            Initial version
 * -------------------------------------------------------------------------------------
 *  2019-04-29        Robbert van Mourik <robbert@webstack.nl>          1.0
 *
 */
class odrdnsaddon
{
    const URI = 'https://api.opendomainregistry.net/';

    public $Error;
    public $Warning;
    public $Success;

    /** @var Client */
    private $client;

    /** @var object */
    public $Settings;

    /**
     * odrdnsaddon constructor.
     */
    public function __construct()
    {
        $this->Error = array();
        $this->Warning = array();
        $this->Success = array();

        $this->client = new Client([
            'base_uri' => self::URI,
        ]);

        $this->loadLanguageArray(LANGUAGE_CODE);
    }

    /**
     * Settings for the integration, these are shown when you add/edit a DNS integration
     *
     * @return string
     */
    public function getPlatformSettings(): string
    {
        $html = '';
        $html .= '<strong class="title">' . __('API-key', 'odrdnsaddon') . '</strong>';
        $html .= '<input type="text" class="text1 size1" name="module[dnsmanagement][Settings][api_key]" value="' . (isset($this->Settings->api_key) ? htmlspecialchars($this->Settings->api_key) : '') . '"><br><br>';
        $html .= '<strong class="title">' . __('API-token', 'odrdnsaddon') . '</strong>';
        $html .= '<input type="text" class="text1 size1" name="module[dnsmanagement][Settings][api_secret]" value="' . (isset($this->Settings->api_secret) ? htmlspecialchars($this->Settings->api_secret) : '') . '"><br><br>';
        $html .= '<strong class="title">' . __('Account', 'odrdnsaddon') . '</strong>';
        $html .= '<input type="text" class="text1 size1" name="module[dnsmanagement][Settings][account]" placeholder="hostmaster@domain.tld" value="' . (isset($this->Settings->account) ? htmlspecialchars($this->Settings->account) : '') . '"><br><br>';

        return $html;
    }

    /**
     * Get the DNS templates from the DNS platform
     *
     * @return bool
     */
    public function getDNSTemplates(): bool
    {
        return false;
    }

    /**
     * This function is called before a add/edit/show of a DNS integration
     * For example, you can use this to encrypt a password
     *
     * @param $edit_or_show
     * @param $settings
     * @return mixed
     */
    public function processSettings($edit_or_show, $settings)
    {
        return $settings;
    }

    /**
     * Create a DNS zone with DNS records on the DNS platform
     *
     * @param $domain
     * @param $dns_zone
     * @return bool
     */
    public function createDNSZone($domain, $dns_zone): bool
    {
        $records = $this->getDNSZone($domain);

        if (!empty($records)) {
            // if the DNS zone already exists, you can edit the DNS zone by using the saveDNSzone function
            if ($this->saveDNSZone($domain, $dns_zone)) {
                return true;
            }

            return false;
        }

        if (isset($dns_zone['records']) && count($dns_zone['records']) > 0) {
            $records = [];

            foreach ($dns_zone['records'] as $record) {
                $records[] = $this->transformRecord($domain, $record);
            }

            $response = $this->client->post('/dns/', [
                'json' => [
                    'hostname' => $domain,
                    'account' => $this->Settings->account,
                    'records' => $records,
                ],
                'headers' => [
                    'X-Access-Token' => $_SESSION['odr_dns_api_token'],
                ],
            ]);

            return $response->getStatusCode() === 201;
        }

        return false;
    }

    /**
     * This function will be called when a domain register, transfer or nameserver change has failed
     *  It can be used to revert any data that is set by the createDNSZone function (eg the creation of a DNS zone)
     *
     * @param $domain
     * @param $create_dns_zone_data
     * @return bool
     */
    public function undoCreateDNSZone($domain, $create_dns_zone_data): bool
    {
        return $this->removeDNSZone($domain);
    }

    /**
     * Retrieve the DNS zone with its DNS records from the DNS platform
     *
     * @param $domain
     * @return array|bool
     */
    public function getDNSZone($domain)
    {
        $result = $this->getZone($domain);

        if ($result) {
            $dns_zone = [];

            $i = 0;

            foreach ($result as $record) {
                // if the record is not supported, it should be marked as readonly
                if (!in_array(strtoupper($record['type']), $this->SupportedRecordTypes, true)) {
                    $record_type = 'records_readonly';
                } else {
                    $record_type = 'records';
                }

                $dns_zone[$record_type][$i]['id'] = $record['id'];
                $dns_zone[$record_type][$i]['name'] = $record['name'];
                $dns_zone[$record_type][$i]['type'] = $record['type'];
                $dns_zone[$record_type][$i]['value'] = $record['value'];

                if (array_key_exists('priority', $record)) {
                    $dns_zone[$record_type][$i]['priority'] = $record['priority'];
                }

                $dns_zone[$record_type][$i]['ttl'] = $record['ttl'];

                $i++;
            }

            return $dns_zone;
        }

        return false;
    }

    /**
     * Edit the DNS zone at the DNS platform
     *
     * @param $domain
     * @param $dns_zone
     * @return bool
     */
    public function saveDNSZone($domain, $dns_zone): bool
    {
        $this->authenticate();

        if (isset($dns_zone['records']) && count($dns_zone['records']) > 0) {
            $id = $this->getDomainId($domain);

            $records = [];

            foreach ($dns_zone['records'] as $record) {
                $records[] = $this->transformRecord($domain, $record);
            }

            $response = $this->client->put('/dns/' . $id, [
                'json' => ['records' => $records],
                'headers' => [
                    'X-Access-Token' => $_SESSION['odr_dns_api_token'],
                ],
            ]);

            return $response->getStatusCode() === 200;
        }

        return false;
    }

    /**
     * This function is called when a domain is removed directly or by a termination procedure
     *
     * @param $domain
     * @return bool
     */
    public function removeDNSZone($domain): bool
    {
        $id = $this->getDomainId($domain);

        $response = $this->client->delete('/dns/' . $id, [
            'headers' => [
                'X-Access-Token' => $_SESSION['odr_dns_api_token'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $data = json_decode($response->getBody()->getContents(), false);

        return !empty($data->response->is_deleted) && true === $data->is_deleted;
    }

    /**
     * @param $language_code
     */
    public function loadLanguageArray($language_code)
    {
        $_LANG = [];

        if ($language_code === 'nl_NL') {
            $_LANG['dns templates could not be retrieved'] = 'De DNS templates konden niet worden opgehaald van het DNS platform of er zijn er geen aanwezig';
        } else { // In case of other language, use English
            $_LANG['dns templates could not be retrieved'] = 'DNS template could not be retrieved from the DNS platform or there were no DNS templates';
        }

        // Save to global array
        global $_module_language_array;

        $_module_language_array['odrdnsaddon'] = $_LANG;
    }

    /**
     * Use this function to prefix all errors messages with your DNS platform
     *
     * @param string $message The error message
     * @return    boolean            Always false
     */
    private function parseError($message): bool
    {
        $this->Error[] = 'odrdnsaddon: ' . $message;

        return false;
    }

    /**
     * @param string $host
     * @return bool|Generator
     */
    public function getZone(string $host)
    {
        $this->authenticate();

        $response = $this->client->get('/dns/?f[hostname]=' . $host, [
            'headers' => [
                'X-Access-Token' => $_SESSION['odr_dns_api_token'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->getHeader('Content-Type')[0] !== 'application/json') {
            return false;
        }

        $data = json_decode($response->getBody()->getContents(), false);

        $id = $data->response[0]->id;

        $response = $this->client->get('/dns/' . $id, [
            'headers' => [
                'X-Access-Token' => $_SESSION['odr_dns_api_token'],
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), false);

        foreach ($data->response->records as $key => $record) {
            if ($record->type === 'MX') {
                list($priority, $value) = explode(' ', $record->content);

                $priority = (int)$priority;
            } else {
                $priority = null;
                $value = $record->content;
            }

            yield [
                'id' => $id . '#' . $key,
                'name' => $record->name,
                'type' => strtoupper($record->type),
                'value' => $value,
                'priority' => $priority,
                'ttl' => $record->ttl,
            ];
        }
    }

    /**
     * @param string $domain
     * @return bool|int
     */
    private function getDomainId(string $domain)
    {
        $this->authenticate();

        $response = $this->client->get('/dns/?f[hostname]=' . $domain, [
            'headers' => [
                'X-Access-Token' => $_SESSION['odr_dns_api_token'],
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->getHeader('Content-Type')[0] !== 'application/json') {
            return false;
        }

        $data = json_decode($response->getBody()->getContents(), false);

        return $data->response[0]->id;
    }

    /**
     * @return bool
     */
    public function authenticate(): bool
    {
        $response = $this->client->post('/user/login/', [
            'json' => $this->generateSignatureData($this->Settings->api_key, $this->Settings->api_secret),
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->getHeader('Content-Type')[0] !== 'application/json') {
            return false;
        }

        $data = json_decode($response->getBody()->getContents(), false);

        $_SESSION['odr_dns_api_token'] = $data->response->token;

        return true;
    }

    /**
     * This function is used to check if the login credentials for the DNS platform are correct
     *
     * @return bool
     */
    public function validateLogin(): bool
    {
        $apiKey = $this->Settings->api_key;
        $apiSecret = $this->Settings->api_secret;

        if ($apiKey === '') {
            $this->parseError('No api-key was set');

            return false;
        }

        if ($apiSecret === '') {
            $this->parseError('No api-secret was set');

            return false;
        }

        if ($this->Settings->account === '') {
            $this->parseError('No account was set');

            return false;
        }

        $response = $this->client->post('/user/login/', [
            'json' => $this->generateSignatureData($apiKey, $apiSecret),
        ]);

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->getHeader('Content-Type')[0] !== 'application/json') {
            return false;
        }

        $data = json_decode($response->getBody()->getContents(), false);

        return !($data->code !== 200);

    }

    /**
     * @param string $apiKey
     * @param string $apiSecret
     * @return array
     */
    private function generateSignatureData(string $apiKey, string $apiSecret): array
    {
        $timestamp = time();

        return [
            'timestamp' => $timestamp,
            'api_key' => $apiKey,
            'signature' => 'token$' . sha1(vsprintf('%s %s %s', [
                    $apiKey,
                    $timestamp,
                    sha1($apiSecret),
                ])),
        ];
    }

    /**
     * @param $domain
     * @param $data
     * @return object
     */
    private function transformRecord($domain, $data)
    {
        $type = $data['type'];

        if ($type === 'SPF') {
            $type = 'TXT';
        }

        if ($data['type'] === 'MX') {
            $content = $data['priority'] . ' ' . $data['value'];
        } else {
            $content = $data['value'];
        }

        return (object)[
            'name' => (!empty($data['name']) ? $data['name'] . '.' : '') . $domain,
            'type' => $type,
            'content' => $content,
        ];
    }
}