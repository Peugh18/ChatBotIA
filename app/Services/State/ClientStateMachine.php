<?php

namespace App\Services\State;

use App\Models\Client;
use Illuminate\Support\Facades\Log;

class ClientStateMachine
{
    /**
     * Estados del flujo de conversación con el cliente.
     */
    public const STEP_START = 'start';
    public const STEP_WAITING_CONFIRMATION = 'waiting_confirmation';
    public const STEP_COLLECT_VARIANT = 'collect_variant';
    public const STEP_COLLECT_SHIPPING = 'collect_shipping';
    public const STEP_COLLECT_DELIVERY_DISTRICT = 'collect_delivery_district';
    public const STEP_COLLECT_PAYMENT_METHOD = 'collect_payment_method';
    public const STEP_AWAITING_PAYMENT = 'awaiting_payment';
    public const STEP_COLLECT_DELIVERY_DETAILS_MOTORIZADO = 'collect_delivery_details_motorizado';
    public const STEP_COLLECT_DELIVERY_DETAILS_SHALOM = 'collect_delivery_details_shalom';

    /**
     * Estados del cliente (status en la tabla clients).
     */
    public const STATUS_NUEVO = 'NUEVO';
    public const STATUS_INTERESADO = 'INTERESADO';
    public const STATUS_CONSULTANDO = 'CONSULTANDO';
    public const STATUS_ESPERANDO_PAGO = 'ESPERANDO PAGO';
    public const STATUS_VERIFICARYAPE = 'VERIFICARYAPE';
    public const STATUS_PAGO_RECIBIDO = 'PAGO RECIBIDO';
    public const STATUS_VENTA_CUMPLIDA = 'VENTA CUMPLIDA';
    public const STATUS_EN_PREPARACION = 'EN PREPARACIÓN';
    public const STATUS_EN_CAMINO = 'EN CAMINO';
    public const STATUS_FINALIZADO = 'FINALIZADO';
    public const STATUS_ABANDONADO = 'ABANDONADO';
    public const STATUS_NECESITA_ASESOR = 'NECESITA ASESOR';

    /**
     * Transiciones permitidas entre steps.
     * Formato: from_step => [to_step1, to_step2, ...]
     */
    private const ALLOWED_TRANSITIONS = [
        self::STEP_START => [
            self::STEP_WAITING_CONFIRMATION,
            self::STEP_COLLECT_VARIANT,
            self::STEP_COLLECT_SHIPPING,
        ],
        self::STEP_WAITING_CONFIRMATION => [
            self::STEP_COLLECT_VARIANT,
            self::STEP_COLLECT_SHIPPING,
            self::STEP_START,
        ],
        self::STEP_COLLECT_VARIANT => [
            self::STEP_COLLECT_SHIPPING,
            self::STEP_START,
        ],
        self::STEP_COLLECT_SHIPPING => [
            self::STEP_COLLECT_DELIVERY_DISTRICT,
            self::STEP_COLLECT_PAYMENT_METHOD,
            self::STEP_COLLECT_DELIVERY_DETAILS_MOTORIZADO,
            self::STEP_COLLECT_DELIVERY_DETAILS_SHALOM,
            self::STEP_START,
        ],
        self::STEP_COLLECT_DELIVERY_DISTRICT => [
            self::STEP_COLLECT_PAYMENT_METHOD,
            self::STEP_START,
        ],
        self::STEP_COLLECT_PAYMENT_METHOD => [
            self::STEP_AWAITING_PAYMENT,
            self::STEP_START,
        ],
        self::STEP_AWAITING_PAYMENT => [
            self::STEP_COLLECT_DELIVERY_DETAILS_MOTORIZADO,
            self::STEP_COLLECT_DELIVERY_DETAILS_SHALOM,
            self::STEP_START,
        ],
        self::STEP_COLLECT_DELIVERY_DETAILS_MOTORIZADO => [
            self::STEP_START,
        ],
        self::STEP_COLLECT_DELIVERY_DETAILS_SHALOM => [
            self::STEP_START,
        ],
    ];

    /**
     * Transiciones permitidas entre statuses.
     */
    private const ALLOWED_STATUS_TRANSITIONS = [
        self::STATUS_NUEVO => [
            self::STATUS_INTERESADO,
            self::STATUS_CONSULTANDO,
            self::STATUS_NECESITA_ASESOR,
        ],
        self::STATUS_INTERESADO => [
            self::STATUS_CONSULTANDO,
            self::STATUS_ESPERANDO_PAGO,
            self::STATUS_NECESITA_ASESOR,
            self::STATUS_ABANDONADO,
        ],
        self::STATUS_CONSULTANDO => [
            self::STATUS_INTERESADO,
            self::STATUS_ESPERANDO_PAGO,
            self::STATUS_NECESITA_ASESOR,
            self::STATUS_ABANDONADO,
        ],
        self::STATUS_ESPERANDO_PAGO => [
            self::STATUS_VERIFICARYAPE,
            self::STATUS_INTERESADO,
            self::STATUS_NECESITA_ASESOR,
            self::STATUS_ABANDONADO,
        ],
        self::STATUS_VERIFICARYAPE => [
            self::STATUS_PAGO_RECIBIDO,
            self::STATUS_ESPERANDO_PAGO,
            self::STATUS_NECESITA_ASESOR,
        ],
        self::STATUS_PAGO_RECIBIDO => [
            self::STATUS_EN_PREPARACION,
            self::STATUS_EN_CAMINO,
            self::STATUS_VENTA_CUMPLIDA,
            self::STATUS_FINALIZADO,
            self::STATUS_NECESITA_ASESOR,
        ],
        self::STATUS_EN_PREPARACION => [
            self::STATUS_EN_CAMINO,
            self::STATUS_VENTA_CUMPLIDA,
            self::STATUS_FINALIZADO,
            self::STATUS_NECESITA_ASESOR,
        ],
        self::STATUS_EN_CAMINO => [
            self::STATUS_VENTA_CUMPLIDA,
            self::STATUS_FINALIZADO,
            self::STATUS_NECESITA_ASESOR,
        ],
        self::STATUS_VENTA_CUMPLIDA => [
            self::STATUS_FINALIZADO,
        ],
        self::STATUS_ABANDONADO => [
            self::STATUS_INTERESADO,
            self::STATUS_CONSULTANDO,
        ],
        self::STATUS_NECESITA_ASESOR => [
            self::STATUS_INTERESADO,
            self::STATUS_CONSULTANDO,
            self::STATUS_ESPERANDO_PAGO,
            self::STATUS_PAGO_RECIBIDO,
        ],
    ];

    /**
     * Verifica si una transición de step es válida.
     */
    public static function canTransitionStep(string $from, string $to): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    /**
     * Verifica si una transición de status es válida.
     */
    public static function canTransitionStatus(string $from, string $to): bool
    {
        $allowed = self::ALLOWED_STATUS_TRANSITIONS[$from] ?? [];
        return in_array($to, $allowed, true);
    }

    /**
     * Transición segura de step.
     * Lanza excepción si la transición no es válida.
     */
    public static function transitionStep(Client $client, string $toStep, array $additionalState = []): void
    {
        $currentState = $client->state ?? ['step' => self::STEP_START];
        $fromStep = $currentState['step'] ?? self::STEP_START;

        if (!self::canTransitionStep($fromStep, $toStep)) {
            Log::warning("Invalid step transition from {$fromStep} to {$toStep} for client {$client->id}");
            throw new \InvalidArgumentException("Transición de step inválida: {$fromStep} -> {$toStep}");
        }

        $newState = array_merge($currentState, $additionalState, ['step' => $toStep]);
        $client->update(['state' => $newState]);
    }

    /**
     * Transición segura de status.
     * Lanza excepción si la transición no es válida.
     */
    public static function transitionStatus(Client $client, string $toStatus, array $additionalData = []): void
    {
        $fromStatus = $client->status;

        if (!self::canTransitionStatus($fromStatus, $toStatus)) {
            Log::warning("Invalid status transition from {$fromStatus} to {$toStatus} for client {$client->id}");
            throw new \InvalidArgumentException("Transición de status inválida: {$fromStatus} -> {$toStatus}");
        }

        $client->update(array_merge($additionalData, ['status' => $toStatus]));
    }

    /**
     * Reinicia el flujo del cliente al estado inicial.
     */
    public static function resetFlow(Client $client): void
    {
        $client->update([
            'state' => ['step' => self::STEP_START],
            'status' => self::STATUS_INTERESADO,
        ]);
    }

    /**
     * Obtiene el step actual del cliente.
     */
    public static function getCurrentStep(Client $client): string
    {
        return $client->state['step'] ?? self::STEP_START;
    }

    /**
     * Verifica si el cliente está en un step específico.
     */
    public static function isStep(Client $client, string $step): bool
    {
        return self::getCurrentStep($client) === $step;
    }

    /**
     * Verifica si el cliente está en un estado específico.
     */
    public static function isStatus(Client $client, string $status): bool
    {
        return $client->status === $status;
    }

    /**
     * Obtiene todos los steps permitidos desde el step actual.
     */
    public static function getAllowedNextSteps(Client $client): array
    {
        $currentStep = self::getCurrentStep($client);
        return self::ALLOWED_TRANSITIONS[$currentStep] ?? [];
    }

    /**
     * Obtiene todos los statuses permitidos desde el status actual.
     */
    public static function getAllowedNextStatuses(Client $client): array
    {
        $currentStatus = $client->status;
        return self::ALLOWED_STATUS_TRANSITIONS[$currentStatus] ?? [];
    }
}
