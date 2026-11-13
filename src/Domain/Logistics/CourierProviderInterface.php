<?php
declare(strict_types=1);
namespace PkStore\Domain\Logistics;
use PkStore\Domain\Logistics\DTO\{ShipmentRequestDTO,ShipmentResultDTO,TrackingUpdateDTO};
interface CourierProviderInterface { public function createShipment(ShipmentRequestDTO $s):ShipmentResultDTO; public function cancelShipment(string $id):bool; public function getTrackingUpdate(string $id):?TrackingUpdateDTO; public function calculateRate(ShipmentRequestDTO $s):?int; public function verifyWebhookSignature(array $payload, array $headers):bool; public function getCourierCode():string; public function getDisplayName():string; public function isHealthy():bool; }
