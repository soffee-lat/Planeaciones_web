<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3B — Suscripciones, periodos y reservas de unidades.
 *
 * Contratos verbatim (DATABASE.md § catálogo comercial):
 *
 *   subscriptions: customer_id, plan_id, status (pending/active/past_due/
 *   cancelled/expired), starts_at, renews_at, cancel_requested_at, ends_at.
 *   Una suscripción vigente por cliente: bloqueo de users e índice único
 *   parcial customer_id WHERE status IN (active,past_due). Cancelación
 *   programada conserva status active hasta ends_at.
 *
 *   subscription_periods: subscription_id, plan_version_id, starts_at,
 *   ends_at, status, entitlement_snapshot JSON, paid_order_id nullable.
 *   UNIQUE(subscription_id,starts_at). Periodos [inicio,fin), sin
 *   solapamiento bajo bloqueo; derechos no cambian al editar catálogo.
 *
 *   usage_reservations: period_id, request_id, correction_request_id
 *   nullable, resource (planning/human_review/client_correction),
 *   operation_key UNIQUE, quantity, status (reserved/consumed/released),
 *   reserved_at, consumed_at, released_at.
 *
 * Deferimientos explícitos (aún no existen en el schema):
 *   - orders.id ⇒ paid_order_id NULLABLE sin FK (se enlazará en 3C).
 *   - correction_requests.id ⇒ correction_request_id NULLABLE sin FK.
 *   - En 3B request_id es nullable: 3D conecta reservas con PlanningRequest.
 */
return new class extends Migration {
    public function up(): void
    {
        // btree_gist requerido para EXCLUDE con igualdad (subscription_id) +
        // rango temporal (tstzrange) sin solapes.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // ------------------------------------------------------------------
        // subscriptions
        // ------------------------------------------------------------------
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->string('status', 32);
            $table->timestampTz('starts_at');
            $table->timestampTz('renews_at')->nullable();
            $table->timestampTz('cancel_requested_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->timestampsTz();

            // FK compuesta preparada para SubscriptionPeriod(plan_version_id, plan_id).
            $table->unique(['id', 'plan_id']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('pending','active','past_due','cancelled','expired'))");

        // Una única suscripción operativa (active|past_due) por cliente.
        DB::statement("CREATE UNIQUE INDEX subscriptions_customer_operational_uniq ON subscriptions (customer_id) WHERE status IN ('active','past_due')");

        // Índice de trabajo para búsquedas por cliente.
        DB::statement('CREATE INDEX subscriptions_customer_idx ON subscriptions (customer_id, status)');

        // Guarda que plan_version.plan_id === subscription.plan_id: se hace vía
        // FK compuesta hacia plan_versions(id, plan_id).
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_plan_version_matches_plan_fk FOREIGN KEY (plan_version_id, plan_id) REFERENCES plan_versions(id, plan_id) ON DELETE RESTRICT');

        // La plan_version debe estar publicada. Trigger BD lo garantiza.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION subscription_plan_version_published_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_published_at timestamptz;
            BEGIN
                SELECT published_at INTO v_published_at FROM plan_versions WHERE id = NEW.plan_version_id FOR SHARE;
                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'SUBSCRIPTION_PLAN_VERSION_NOT_PUBLISHED';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER subscriptions_plan_version_published_trg BEFORE INSERT OR UPDATE OF plan_version_id ON subscriptions FOR EACH ROW EXECUTE FUNCTION subscription_plan_version_published_guard()');

        // ------------------------------------------------------------------
        // subscription_periods
        // ------------------------------------------------------------------
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('plan_id'); // Redundante para la FK compuesta.
            $table->unsignedBigInteger('plan_version_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 32);
            $table->jsonb('entitlement_snapshot');
            $table->unsignedBigInteger('paid_order_id')->nullable();
            $table->timestampsTz();

            $table->unique(['subscription_id', 'starts_at']);
        });

        // FK compuesta: la subscription y el period comparten plan_id, y el
        // plan_version_id debe pertenecer al mismo plan.
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_subscription_fk FOREIGN KEY (subscription_id, plan_id) REFERENCES subscriptions(id, plan_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_plan_version_fk FOREIGN KEY (plan_version_id, plan_id) REFERENCES plan_versions(id, plan_id) ON DELETE RESTRICT');

        DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_status_check CHECK (status IN ('pending','active','ended','cancelled'))");
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_range_check CHECK (ends_at > starts_at)');

        // Exclusion constraint: para una misma subscription no puede existir
        // solape entre periodos considerados vigentes (pending|active). Los
        // periodos ended/cancelled quedan fuera de la restricción.
        DB::statement(<<<'SQL'
            ALTER TABLE subscription_periods
              ADD CONSTRAINT subscription_periods_no_overlap
              EXCLUDE USING gist (
                subscription_id WITH =,
                tstzrange(starts_at, ends_at, '[)') WITH &&
              ) WHERE (status IN ('pending','active'))
        SQL);

        DB::statement('CREATE INDEX subscription_periods_lookup_idx ON subscription_periods (subscription_id, status)');

        // ------------------------------------------------------------------
        // usage_reservations
        // ------------------------------------------------------------------
        Schema::create('usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_period_id')->constrained('subscription_periods')->restrictOnDelete();
            // request_id preparado para 3D. En 3B queda nullable y sin FK
            // porque los tests unitarios pueden crear reservas sin request.
            $table->foreignId('planning_request_id')->nullable()->constrained('planning_requests')->nullOnDelete();
            // correction_request_id: la tabla correction_requests aún no
            // existe (3C/3D). Se añade nullable sin FK.
            $table->unsignedBigInteger('correction_request_id')->nullable();
            $table->string('resource', 32);
            $table->string('operation_key');
            $table->unsignedInteger('quantity');
            $table->string('status', 32);
            $table->timestampTz('reserved_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->unique('operation_key');
        });

        DB::statement("ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_resource_check CHECK (resource IN ('planning','human_review','client_correction'))");
        DB::statement("ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_status_check CHECK (status IN ('reserved','consumed','released'))");
        DB::statement('ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_quantity_positive CHECK (quantity > 0)');
        // Sólo consumed lleva consumed_at, sólo released lleva released_at.
        DB::statement("ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_consumed_at_check CHECK ((status = 'consumed') = (consumed_at IS NOT NULL))");
        DB::statement("ALTER TABLE usage_reservations ADD CONSTRAINT usage_reservations_released_at_check CHECK ((status = 'released') = (released_at IS NOT NULL))");

        DB::statement('CREATE INDEX usage_reservations_period_resource_status_idx ON usage_reservations (subscription_period_id, resource, status)');

        // Trigger de inmutabilidad de transiciones: bloquea reserved→reserved
        // con cambios de resource/quantity/period, y bloquea salidas de
        // consumed/released hacia otros estados. Sólo permite dos rutas:
        //   reserved → consumed
        //   reserved → released
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION usage_reservation_transition_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'reserved' THEN
                        RAISE EXCEPTION 'USAGE_RESERVATION_IMMUTABLE_AFTER_TERMINAL: cannot delete % reservation %', OLD.status, OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF OLD.subscription_period_id <> NEW.subscription_period_id THEN
                        RAISE EXCEPTION 'USAGE_RESERVATION_PERIOD_LOCKED';
                    END IF;
                    IF OLD.resource <> NEW.resource THEN
                        RAISE EXCEPTION 'USAGE_RESERVATION_RESOURCE_LOCKED';
                    END IF;
                    IF OLD.quantity <> NEW.quantity THEN
                        RAISE EXCEPTION 'USAGE_RESERVATION_QUANTITY_LOCKED';
                    END IF;
                    IF OLD.operation_key <> NEW.operation_key THEN
                        RAISE EXCEPTION 'USAGE_RESERVATION_OPERATION_KEY_LOCKED';
                    END IF;

                    IF OLD.status <> NEW.status THEN
                        IF OLD.status <> 'reserved' THEN
                            RAISE EXCEPTION 'USAGE_RESERVATION_TERMINAL_STATE: % cannot transition to %', OLD.status, NEW.status;
                        END IF;
                        IF NEW.status NOT IN ('consumed','released') THEN
                            RAISE EXCEPTION 'USAGE_RESERVATION_INVALID_TRANSITION: reserved -> %', NEW.status;
                        END IF;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER usage_reservations_transition_trg BEFORE UPDATE OR DELETE ON usage_reservations FOR EACH ROW EXECUTE FUNCTION usage_reservation_transition_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS usage_reservations_transition_trg ON usage_reservations');
        DB::statement('DROP FUNCTION IF EXISTS usage_reservation_transition_guard()');
        Schema::dropIfExists('usage_reservations');

        Schema::dropIfExists('subscription_periods');

        DB::statement('DROP TRIGGER IF EXISTS subscriptions_plan_version_published_trg ON subscriptions');
        DB::statement('DROP FUNCTION IF EXISTS subscription_plan_version_published_guard()');
        Schema::dropIfExists('subscriptions');
    }
};
