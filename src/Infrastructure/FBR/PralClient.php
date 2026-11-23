<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\FBR;

use PkStore\Domain\FBR\Exception\PralApiException;
use PkStore\License\LicenseManager;

/**
 * PRAL Digital Invoicing API client (FBR DI API v1.12).
 *
 * The bearer token and environment are provisioned per customer installation
 * by Junction's central platform. API hosts and paths are fixed here so a
 * store-level setting cannot redirect taxpayer credentials to another host.
 */
final class PralClient {
    private const BASE_URL = 'https://gw.fbr.gov.pk';
    private const REFERENCE_TTL = 21600;

    private const REFERENCE_ENDPOINTS = [
        'provinces' => '/pdi/v1/provinces',
        'document_types' => '/pdi/v1/doctypecode',
        'item_descriptions' => '/pdi/v1/itemdesccode',
        'sro_items' => '/pdi/v1/sroitemcode',
        'transaction_types' => '/pdi/v1/transtypecode',
        'units_of_measure' => '/pdi/v1/uom',
        'sro_schedules' => '/pdi/v1/srosched',
        'sale_type_to_rate' => '/pdi/v2/SaleTypeToRate',
        'hs_uom' => '/pdi/v2/HS_UOM',
        'sro_item' => '/pdi/v2/SROItem',
    ];

    public function __construct(private readonly LicenseManager $license) {}

    public function validateInvoice(array $payload): array {
        return $this->post($this->invoicePath('validateinvoicedata'), $payload, false);
    }

    public function postInvoice(array $payload): array {
        return $this->post($this->invoicePath('postinvoicedata'), $payload, true);
    }

    /**
     * Reference data is cached per token fingerprint and query. It is never
     * shared between customer installations or sandbox/production tokens.
     */
    public function reference(string $name, array $query = []): array {
        $config = $this->configuration();
        $path = self::REFERENCE_ENDPOINTS[$name] ?? null;
        if (!is_string($path)) {
            throw new \InvalidArgumentException('Unsupported PRAL reference-data method.');
        }

        ksort($query);
        $cacheKey = 'pkstore_pral_ref_' . substr(hash('sha256',
            $config['environment'] . '|' . hash('sha256', $config['api_token']) . '|' . $path . '|' . wp_json_encode($query)
        ), 0, 40);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return $cached;

        $url = self::BASE_URL . $path;
        if ($query) $url = add_query_arg($query, $url);
        $result = $this->request('GET', $url, null, false, $config['api_token']);
        set_transient($cacheKey, $result, self::REFERENCE_TTL);
        return $result;
    }

    public function saleTypeToRate(string $date, string $transactionTypeId, string $supplierProvince): array {
        return $this->reference('sale_type_to_rate', [
            'date' => $date,
            'transTypeId' => $transactionTypeId,
            'originationSupplier' => $supplierProvince,
        ]);
    }

    public function hsUom(string $hsCode, string $annexureId = '3'): array {
        return $this->reference('hs_uom', [
            'hs_code' => $hsCode,
            'annexure_id' => $annexureId,
        ]);
    }

    public function sroItems(string $date, string $sroId): array {
        return $this->reference('sro_item', [
            'date' => $date,
            'sro_id' => $sroId,
        ]);
    }

    private function invoicePath(string $method): string {
        $config = $this->configuration();
        $suffix = $config['environment'] === 'sandbox' ? '_sb' : '';
        return self::BASE_URL . '/di_data/v1/di/' . $method . $suffix;
    }

    private function post(string $path, array $payload, bool $invoiceSubmission): array {
        $config = $this->configuration();
        return $this->request('POST', $path, $payload, $invoiceSubmission, $config['api_token']);
    }

    private function request(
        string $method,
        string $url,
        ?array $payload,
        bool $ambiguousOnTransportFailure,
        string $apiToken
    ): array {
        $args = [
            'method' => $method,
            'timeout' => 30,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $apiToken,
            ],
        ];

        if ($payload !== null) {
            $encoded = wp_json_encode($payload);
            if (!is_string($encoded)) {
                throw new PralApiException('Unable to encode the PRAL request payload.');
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded;
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            throw new PralApiException(
                'PRAL did not return a response. Check the taxpayer connection before retrying.',
                $ambiguousOnTransportFailure,
                null,
                $response
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($status === 401) {
            throw new PralApiException('PRAL rejected this installation token (HTTP 401).', false, $status);
        }
        if ($status >= 500) {
            throw new PralApiException(
                'PRAL returned a server error (HTTP ' . $status . ').',
                $ambiguousOnTransportFailure,
                $status
            );
        }
        if ($status < 200 || $status >= 300) {
            throw new PralApiException('PRAL rejected the request (HTTP ' . $status . ').', false, $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new PralApiException(
                'PRAL returned an unreadable response. Verify the invoice in the FBR portal before retrying.',
                $ambiguousOnTransportFailure,
                $status
            );
        }

        return $decoded;
    }

    /** @return array{environment: string, api_token: string} */
    private function configuration(): array {
        $config = $this->license->getFbrProviderConfig();
        if (empty($config['api_token']) || !in_array($config['environment'] ?? '', ['sandbox', 'production'], true)) {
            throw new PralApiException('FBR/PRAL is not configured for this customer installation.');
        }
        return [
            'environment' => $config['environment'],
            'api_token' => $config['api_token'],
        ];
    }
}
