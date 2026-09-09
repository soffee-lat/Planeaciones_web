<?php

namespace Tests\Unit;

use App\Enums\PlanningRequestStatus;
use App\Services\Planning\PlanningRequestStateMachine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PlanningRequestStateMachineTest extends TestCase
{
    public function test_permite_arranque_del_pipeline_y_rechaza_salto_de_auditoria(): void
    {
        $machine = new PlanningRequestStateMachine();

        $this->assertTrue($machine->canTransition(
            PlanningRequestStatus::LISTA_PARA_PROCESAR,
            PlanningRequestStatus::GENERACION_IA,
        ));
        $this->assertFalse($machine->canTransition(
            PlanningRequestStatus::LISTA_PARA_PROCESAR,
            PlanningRequestStatus::AUDITORIA_IA,
        ));
    }

    public function test_cancelada_es_terminal_y_no_se_permite_autotransicion(): void
    {
        $machine = new PlanningRequestStateMachine();

        $this->assertFalse($machine->canTransition(
            PlanningRequestStatus::CANCELADA,
            PlanningRequestStatus::GENERACION_IA,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_REQUEST_TRANSITION_NOT_ALLOWED:GENERACION_IA:GENERACION_IA');
        $machine->assertCanTransition(
            PlanningRequestStatus::GENERACION_IA,
            PlanningRequestStatus::GENERACION_IA,
        );
    }
}
