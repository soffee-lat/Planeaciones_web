<?php

namespace App\Services\Commerce\Gateway;

/**
 * Datos que aporta el administrador al registrar un pago manual.
 *
 * NO contiene ningún dato sensible de tarjeta ni secretos de gateway. La
 * referencia es un texto libre visible en la conciliación bancaria (folio de
 * transferencia, número de recibo, etc.) que sirve como identificador único
 * dentro del provider="manual".
 */
final readonly class ManualPaymentInput
{
    public function __construct(
        public int $amountMinor,
        public string $currency,
        public string $providerReference,
        public string $method,
        public \Carbon\CarbonInterface $occurredAt,
        public ?string $notes = null,
    ) {
    }
}
