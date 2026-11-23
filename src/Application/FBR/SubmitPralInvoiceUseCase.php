<?php
declare(strict_types=1);
namespace PkStore\Application\FBR;

use PkStore\Domain\FBR\Exception\PralApiException;
use PkStore\Infrastructure\FBR\PralClient;
use PkStore\Infrastructure\FBR\PralInvoiceResponseParser;

/**
 * Performs PRAL's validate-then-post sequence. An ambiguous post is returned
 * as "unknown" and must never be retried automatically because PRAL may have
 * issued the invoice before the connection failed.
 */
final class SubmitPralInvoiceUseCase {
    public function __construct(
        private readonly PralClient $client,
        private readonly PralInvoiceResponseParser $parser
    ) {}

    /**
     * @return array{status: string, invoice_number: string, errors: array, retry_safe: bool}
     */
    public function execute(array $payload): array {
        $items = $payload['items'] ?? null;
        if (!is_array($items) || !$items) {
            throw new \InvalidArgumentException('A PRAL invoice must contain at least one item.');
        }

        $expectedItemCount = count($items);
        $validation = $this->client->validateInvoice($payload);
        $validationResult = $this->parser->parse($validation, $expectedItemCount, false);

        if ($validationResult['status'] !== 'valid') {
            return [
                'status' => $validationResult['status'],
                'invoice_number' => '',
                'errors' => $validationResult['errors'],
                'retry_safe' => true,
            ];
        }

        try {
            $posted = $this->client->postInvoice($payload);
        } catch (PralApiException $error) {
            if ($error->ambiguous) {
                return [
                    'status' => 'unknown',
                    'invoice_number' => '',
                    'errors' => [['code' => (string) ($error->httpStatus ?? ''), 'message' => $error->getMessage(), 'item' => '']],
                    'retry_safe' => false,
                ];
            }
            if ($error->httpStatus === 401) {
                return [
                    'status' => 'unauthorized',
                    'invoice_number' => '',
                    'errors' => [['code' => '401', 'message' => $error->getMessage(), 'item' => '']],
                    'retry_safe' => false,
                ];
            }
            return [
                'status' => 'rejected',
                'invoice_number' => '',
                'errors' => [['code' => (string) ($error->httpStatus ?? ''), 'message' => $error->getMessage(), 'item' => '']],
                'retry_safe' => true,
            ];
        }

        $postedResult = $this->parser->parse($posted, $expectedItemCount, true);
        return [
            'status' => $postedResult['status'],
            'invoice_number' => $postedResult['invoice_number'],
            'errors' => $postedResult['errors'],
            'retry_safe' => $postedResult['status'] === 'rejected',
        ];
    }
}
