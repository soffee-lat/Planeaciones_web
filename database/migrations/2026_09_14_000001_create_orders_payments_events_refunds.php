<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3C — Órdenes, pagos, eventos y devoluciones.
 *
 * Verbatim DATABASE.md:
 *   orders: customer_id, subscription_period_id nullable, concept, total_minor,
 *   currency, status, idempotency_key UNIQUE, paid_at. Compra o renovación;
 *   múltiples intentos de pago.
 *
 *   payments: order_id, customer_id, provider, provider_reference nullable,
 *   method, amount_minor, currency, status (pending/succeeded/failed/cancelled),
 *   occurred_at, confirmed_by nullable. UNIQUE(provider, provider_reference).
 *   Sin datos de tarjeta. Referencia manual también única.
 *
 *   payment_events: provider, event_id, payload_hash, status, received_at,
 *   processed_at, error_code. UNIQUE(provider, event_id), conservar solo
 *   payload mínimo necesario.
 *
 *   refunds: payment_id, amount_minor, status, reason, provider_reference,
 *   idempotency_key UNIQUE, completed_at. Suma confirmada <= pago bajo
 *   bloqueo; devoluciones parciales admitidas.
 *
 * Extensiones documentadas (necesarias para reconstruir la compra y activar
 * comercialmente):
 *   - orders.plan_id y orders.plan_version_id: fotografía obligatoria del
 *     plan comprado (DATABASE.md exige que la Order pueda reconstruir qué
 *     compró el cliente sin depender del catálogo actual).
 *   - orders.activated_subscription_id NULLABLE: link idempotente a la
 *     Subscription creada durante ActivatePaidOrder. Verbatim requirement:
 *     "Reprocesar una Order pagada NO debe crear segunda Subscription".
 *   - orders.created_by NULLABLE (admin que originó la orden manual).
 *
 * Diferimientos explícitos:
 *   - `request_financial_allocations` se aplaza a 3D: DATABASE.md la define
 *     con FK a `planning_requests` y basis 'planning_units', su semántica
 *     "congelado al consumir" pertenece al consumo real de unidades (3D).
 *   - Renovación automática, cron, webhook real de terceros → fuera de MVP.
 */
return new class extends Migration {
    public function up(): void
    {
        // ------------------------------------------------------------------
        // orders
        // ------------------------------------------------------------------
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedBigInteger('plan_version_id');
            $table->foreignId('subscription_period_id')->nullable()->constrained('subscription_periods')->nullOnDelete();
            $table->foreignId('activated_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('concept', 255);
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
        });

        // FK compuesta (plan_version_id, plan_id) → coherencia como en subscriptions.
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_plan_version_matches_plan_fk FOREIGN KEY (plan_version_id, plan_id) REFERENCES plan_versions(id, plan_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending','paid','cancelled','refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_total_positive CHECK (total_minor >= 0)');
        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_paid_at_consistency CHECK ((status IN ('paid','refunded')) = (paid_at IS NOT NULL))");
        DB::statement('CREATE INDEX orders_customer_status_idx ON orders (customer_id, status)');

        // La Order sólo puede apuntar a plan_versions publicadas (trigger BD).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_plan_version_published_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_published_at timestamptz;
            BEGIN
                SELECT published_at INTO v_published_at FROM plan_versions WHERE id = NEW.plan_version_id FOR SHARE;
                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'ORDER_PLAN_VERSION_NOT_PUBLISHED';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER orders_plan_version_published_trg BEFORE INSERT OR UPDATE OF plan_version_id ON orders FOR EACH ROW EXECUTE FUNCTION order_plan_version_published_guard()');

        // Inmutabilidad post-pago: bloquea cambios de monto/moneda/plan_version en órdenes pagadas.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION order_paid_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status IN ('paid','refunded') THEN
                        RAISE EXCEPTION 'ORDER_PAID_IMMUTABLE: cannot delete order %', OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status IN ('paid','refunded') THEN
                    IF (NEW.total_minor <> OLD.total_minor)
                       OR (NEW.currency <> OLD.currency)
                       OR (NEW.plan_version_id <> OLD.plan_version_id)
                       OR (NEW.plan_id <> OLD.plan_id)
                       OR (NEW.customer_id <> OLD.customer_id)
                       OR (NEW.idempotency_key <> OLD.idempotency_key)
                    THEN
                        RAISE EXCEPTION 'ORDER_PAID_IMMUTABLE';
                    END IF;
                END IF;
                IF OLD.status = 'cancelled' AND NEW.status <> 'cancelled' THEN
                    RAISE EXCEPTION 'ORDER_CANCELLED_TERMINAL';
                END IF;
                IF OLD.status = 'refunded' AND NEW.status <> 'refunded' THEN
                    RAISE EXCEPTION 'ORDER_REFUNDED_TERMINAL';
                END IF;
                IF OLD.status = 'paid' AND NEW.status NOT IN ('paid','refunded') THEN
                    RAISE EXCEPTION 'ORDER_INVALID_TRANSITION: paid -> %', NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER orders_paid_immutability_trg BEFORE UPDATE OR DELETE ON orders FOR EACH ROW EXECUTE FUNCTION order_paid_immutability_guard()');

        // FK diferida: subscription_periods.paid_order_id → orders.id.
        DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_paid_order_fk FOREIGN KEY (paid_order_id) REFERENCES orders(id) ON DELETE SET NULL');

        // ------------------------------------------------------------------
        // payments
        // ------------------------------------------------------------------
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 64);
            $table->string('provider_reference', 255)->nullable();
            $table->string('method', 64);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 32);
            $table->timestampTz('occurred_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','succeeded','failed','cancelled'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount_minor >= 0)');
        // UNIQUE(provider, provider_reference): dedupe manual y de gateway.
        // Nota: `provider_reference` es NULLable; en Postgres UNIQUE con NULL
        // permite múltiples filas con NULL, así que además exigimos que un
        // payment succeeded siempre tenga provider_reference no nulo.
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_provider_reference_uniq UNIQUE (provider, provider_reference)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_succeeded_has_reference CHECK (status <> 'succeeded' OR provider_reference IS NOT NULL)");
        DB::statement('CREATE INDEX payments_order_status_idx ON payments (order_id, status)');

        // Un único payment succeeded por order.
        DB::statement("CREATE UNIQUE INDEX payments_order_succeeded_uniq ON payments (order_id) WHERE status = 'succeeded'");

        // Inmutabilidad post-terminal: succeeded/failed/cancelled no vuelven.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_transition_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'succeeded' THEN
                        RAISE EXCEPTION 'PAYMENT_SUCCEEDED_IMMUTABLE';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'succeeded' AND NEW.status <> 'succeeded' THEN
                    RAISE EXCEPTION 'PAYMENT_SUCCEEDED_IMMUTABLE';
                END IF;
                IF OLD.status IN ('failed','cancelled') AND NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'PAYMENT_TERMINAL_STATE: % cannot transition to %', OLD.status, NEW.status;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER payments_transition_trg BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION payment_transition_guard()');

        // ------------------------------------------------------------------
        // payment_events
        // ------------------------------------------------------------------
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('event_id', 255);
            $table->char('payload_hash', 64);
            $table->string('status', 32);
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE payment_events ADD CONSTRAINT payment_events_provider_event_uniq UNIQUE (provider, event_id)');
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_status_check CHECK (status IN ('received','processed','ignored','failed'))");
        DB::statement("ALTER TABLE payment_events ADD CONSTRAINT payment_events_processed_at_consistency CHECK ((status IN ('processed','failed','ignored')) = (processed_at IS NOT NULL))");
        DB::statement('CREATE INDEX payment_events_lookup_idx ON payment_events (provider, status, received_at)');

        // ------------------------------------------------------------------
        // refunds
        // ------------------------------------------------------------------
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 32);
            $table->string('reason', 255);
            $table->string('provider_reference', 255)->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending','succeeded','failed','cancelled'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_completed_at_consistency CHECK ((status = 'succeeded') = (completed_at IS NOT NULL))");
        DB::statement('CREATE INDEX refunds_payment_status_idx ON refunds (payment_id, status)');

        // Refunds succeeded: SUM(amount_minor) <= payments.amount_minor. Enforcement
        // se realiza en la Action con lockForUpdate del Payment (DATABASE.md
        // verbatim: "Suma confirmada <= pago bajo bloqueo"). No es factible
        // enforcarlo con CHECK porque involucra agregación entre filas.
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payments_transition_trg ON payments');
        DB::statement('DROP FUNCTION IF EXISTS payment_transition_guard()');
        DB::statement('DROP TRIGGER IF EXISTS orders_paid_immutability_trg ON orders');
        DB::statement('DROP FUNCTION IF EXISTS order_paid_immutability_guard()');
        DB::statement('DROP TRIGGER IF EXISTS orders_plan_version_published_trg ON orders');
        DB::statement('DROP FUNCTION IF EXISTS order_plan_version_published_guard()');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        DB::statement('ALTER TABLE subscription_periods DROP CONSTRAINT IF EXISTS subscription_periods_paid_order_fk');
        Schema::dropIfExists('orders');
    }
};
