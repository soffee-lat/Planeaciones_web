<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3A — Planes y reglas comerciales.
 *
 * Crea la identidad estable del producto (plans) y las fotografías comerciales
 * versionadas (plan_versions). Sigue el patrón de curriculum_versions: una
 * versión con published_at NOT NULL queda inmutable mediante trigger PL/pgSQL.
 *
 * NO se crean todavía suscripciones, periodos, reservas ni pagos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedInteger('number');

            // Reglas comerciales (calendar_days_v1). Todas obligatorias en MVP.
            $table->unsignedBigInteger('price_minor'); // centavos (ej. 12345 = 123.45)
            $table->string('currency', 8); // ISO 4217
            $table->string('interval_unit', 16); // 'month' | 'year'
            $table->unsignedInteger('interval_count'); // 1, 12, ...

            $table->unsignedInteger('max_planning_days'); // M en U=ceil(D/M)
            $table->unsignedInteger('planning_limit'); // unidades por periodo
            $table->unsignedInteger('human_review_limit'); // unidades con revisión humana por periodo
            $table->unsignedInteger('correction_limit'); // rondas por solicitud
            $table->unsignedInteger('group_limit'); // grupos activos por suscripción
            $table->unsignedInteger('correction_window_days')->default(0);
            $table->unsignedInteger('sla_hours')->default(0);

            $table->boolean('human_review_required')->default(false);
            $table->jsonb('features')->nullable();

            // Vigencia informativa (no reemplaza a subscription_periods).
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();

            // Publicación / inmutabilidad.
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checksum', 128)->nullable();

            $table->timestampsTz();

            $table->unique(['plan_id', 'number']);
            $table->unique(['id', 'plan_id']); // Para FKs compuestas futuras (subscription_periods).
        });

        // CHECK constraints a nivel PostgreSQL.
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_max_planning_days_positive CHECK (max_planning_days > 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_planning_limit_positive CHECK (planning_limit > 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_group_limit_positive CHECK (group_limit > 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_interval_count_positive CHECK (interval_count > 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_human_review_limit_non_negative CHECK (human_review_limit >= 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_correction_limit_non_negative CHECK (correction_limit >= 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_price_non_negative CHECK (price_minor >= 0)");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_interval_unit_check CHECK (interval_unit IN ('month','year'))");
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_effective_range CHECK (effective_from IS NULL OR effective_until IS NULL OR effective_until >= effective_from)");
        // Coherencia revisión humana: si human_review_required=true, human_review_limit debe ser >0.
        DB::statement("ALTER TABLE plan_versions ADD CONSTRAINT plan_versions_human_review_coherence CHECK (human_review_required = false OR human_review_limit > 0)");

        // Trigger de inmutabilidad de plan_versions publicadas.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION plan_version_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.published_at IS NOT NULL THEN
                        RAISE EXCEPTION 'PLAN_VERSION_PUBLISHED: cannot delete published version %', OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'PLAN_VERSION_PUBLISHED: cannot update published version %', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.published_at IS NULL AND NEW.published_at IS NOT NULL THEN
                    IF NEW.published_by IS NULL THEN
                        RAISE EXCEPTION 'PLAN_VERSION_INVALID_PUBLISH: published_by is required when publishing';
                    END IF;
                    IF NEW.checksum IS NULL OR length(NEW.checksum) = 0 THEN
                        RAISE EXCEPTION 'PLAN_VERSION_INVALID_PUBLISH: checksum is required when publishing';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER plan_versions_immutability_trg BEFORE UPDATE OR DELETE ON plan_versions FOR EACH ROW EXECUTE FUNCTION plan_version_immutability_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS plan_versions_immutability_trg ON plan_versions');
        DB::statement('DROP FUNCTION IF EXISTS plan_version_immutability_guard()');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
    }
};
