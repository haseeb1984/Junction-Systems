<?php
declare(strict_types=1);
namespace PkStore\Infrastructure\FBR;

/**
 * Interprets PRAL validationResponse without trusting only the header status.
 * PRAL can return header statusCode "00" while an individual item is invalid.
 */
final class PralInvoiceResponseParser {
    /**
     * @return array{status: string, invoice_number: string, errors: array<int, array{code: string, message: string, item: string}>}
     */
    public function parse(array $response, int $expectedItemCount, bool $requireInvoiceNumber): array {
        $validation = $response['validationResponse'] ?? null;
        if (!is_array($validation)) {
            return ['status' => 'unknown', 'invoice_number' => '', 'errors' => []];
        }

        $headerCode = (string) ($validation['statusCode'] ?? '');
        $headerStatus = strtolower(trim((string) ($validation['status'] ?? '')));
        $invoiceNumber = is_string($response['invoiceNumber'] ?? null)
            ? trim($response['invoiceNumber'])
            : '';

        $errors = [];
        $headerError = trim((string) ($validation['error'] ?? ''));
        $headerErrorCode = trim((string) ($validation['errorCode'] ?? ''));
        if ($headerCode !== '00' || $headerStatus !== 'valid') {
            $errors[] = [
                'code' => $headerErrorCode,
                'message' => $headerError !== '' ? $headerError : 'PRAL rejected the invoice header.',
                'item' => '',
            ];
        }

        $itemStatuses = $validation['invoiceStatuses'] ?? null;
        if (!is_array($itemStatuses) || count($itemStatuses) !== $expectedItemCount) {
            return [
                'status' => $errors ? 'rejected' : 'unknown',
                'invoice_number' => $invoiceNumber,
                'errors' => $errors,
            ];
        }

        foreach ($itemStatuses as $itemStatus) {
            if (!is_array($itemStatus)) {
                $errors[] = ['code' => '', 'message' => 'PRAL returned an unreadable item status.', 'item' => ''];
                continue;
            }

            $code = (string) ($itemStatus['statusCode'] ?? '');
            $status = strtolower(trim((string) ($itemStatus['status'] ?? '')));
            if ($code !== '00' || $status !== 'valid') {
                $errors[] = [
                    'code' => trim((string) ($itemStatus['errorCode'] ?? '')),
                    'message' => trim((string) ($itemStatus['error'] ?? '')) ?: 'PRAL rejected an invoice item.',
                    'item' => trim((string) ($itemStatus['itemSNo'] ?? '')),
                ];
            }
        }

        if ($errors) {
            return ['status' => 'rejected', 'invoice_number' => $invoiceNumber, 'errors' => $errors];
        }
        if ($requireInvoiceNumber && $invoiceNumber === '') {
            return ['status' => 'unknown', 'invoice_number' => '', 'errors' => []];
        }

        return ['status' => 'valid', 'invoice_number' => $invoiceNumber, 'errors' => []];
    }
}
