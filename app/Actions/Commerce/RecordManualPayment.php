<?php

namespace App\Actions\Commerce;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commerce\Gateway\ManualPaymentGateway;
use App\Services\Commerce\Gateway\ManualPaymentInput;

/**
 * Wrapper de dominio que delega en el gateway `manual` para registrar un
 * pago administrativo confirmado fuera de banda. Ver `ManualPaymentGateway`
 * para la lógica transaccional, de idempotencia y de validación.
 *
 * Existe como Action separada para (a) mantener el contrato de dominio
 * homogéneo con el resto de Actions de 3B/3C, y (b) permitir sustituir el
 * gateway sin cambiar los llamadores (Filament, tests, futuras UIs admin).
 */
class RecordManualPayment
{
    public function __construct(
        private readonly ManualPaymentGateway $gateway = new ManualPaymentGateway(),
    ) {
    }

    public function __invoke(Order $order, ManualPaymentInput $input, User $confirmedBy): Payment
    {
        return $this->gateway->recordManualPayment($order, $input, $confirmedBy);
    }
}
