<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Subfase 2C — planeaciones: PlanningRequest, snapshot inmutable, pivotes
 * curriculares y eventos de estado. Se omiten las columnas comerciales que
 * dependen de tablas aún inexistentes (subscription_period_id,
 * planning_days, planning_units, commercial_calculation_snapshot,
 * entitlement_snapshot, format_version_id). Se añadirán con FKs reales en
 * su propia migración cuando existan planes/periodos/formatos (Fase 3+).
 */
return new class extends Migration {
    public function up(): void
    {
        // Índice único auxiliar en pdas para poder anclar FK compuesta
        // (pda_id, curriculum_version_id, grade_id) desde request_pdas.
        DB::statement('CREATE UNIQUE INDEX pdas_id_version_grade_unique ON pdas (id, curriculum_version_id, grade_id)');

        Schema::create('planning_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('grade_id');

            $table->string('creation_mode', 16)->nullable(); // quick|advanced — lo escribirá el wizard 2D.
            $table->unsignedInteger('selection_revision')->default(0);
            $table->timestampTz('curriculum_confirmed_at')->nullable();

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('period_label', 64)->nullable();
            $table->string('project', 255)->nullable();
            $table->text('topic')->nullable();
            $table->text('book_pages')->nullable();
            $table->text('required_activities')->nullable();
            $table->text('special_events')->nullable();
            $table->text('comments')->nullable();
            $table->text('pedagogical_notes')->nullable();
            $table->text('suggested_initial_assessment')->nullable();
            $table->text('requested_assessment')->nullable();

            $table->string('status', 32)->default('BORRADOR');
            $table->timestampTz('due_at')->nullable();

            $table->unsignedInteger('input_revision')->default(0);
            $table->jsonb('input_snapshot')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);

            $table->timestampsTz();

            $table->index(['owner_id', 'created_at']);
            $table->index(['status', 'due_at']);
            $table->index('group_id');
            $table->unique(['id', 'owner_id'], 'planning_requests_id_owner_unique');
            $table->unique(['id', 'curriculum_version_id'], 'planning_requests_id_version_unique');
            $table->unique(['id', 'grade_id'], 'planning_requests_id_grade_unique');
        });

        DB::statement("ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_status_check CHECK (status IN ('BORRADOR','ESPERANDO_INFORMACION','ESPERANDO_PAGO','LISTA_PARA_PROCESAR','GENERACION_IA','AUDITORIA_IA','CORRECCION_IA','REVISION_HUMANA','APROBADA','GENERANDO_DOCUMENTO','LISTA_PARA_ENTREGAR','ENTREGADA','COMPLETADA','CORRECCION_SOLICITADA','CANCELADA'))");
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_dates_check CHECK (starts_on IS NULL OR ends_on IS NULL OR ends_on >= starts_on)');

        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_group_owner_fk FOREIGN KEY (group_id, owner_id) REFERENCES groups(id, owner_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_version_fk FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions(id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_grade_version_fk FOREIGN KEY (grade_id, curriculum_version_id) REFERENCES grades(id, curriculum_version_id) ON DELETE RESTRICT');

        // ----------------------------------------------------------------
        // Snapshots inmutables (append-only)
        // ----------------------------------------------------------------
        Schema::create('request_input_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->unsignedInteger('revision');
            $table->jsonb('snapshot');
            $table->jsonb('manifest')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['request_id', 'revision']);
            $table->index('request_id');
        });

        DB::statement('ALTER TABLE request_input_versions ADD CONSTRAINT riv_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE request_input_versions ADD CONSTRAINT riv_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL');

        // FK circular: planning_requests.current_version_id → request_input_versions.id
        DB::statement('ALTER TABLE planning_requests ADD CONSTRAINT planning_requests_current_version_fk FOREIGN KEY (current_version_id) REFERENCES request_input_versions(id) ON DELETE SET NULL');

        // Guard de inmutabilidad: bloquear UPDATE/DELETE en request_input_versions.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION request_input_version_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'REQUEST_INPUT_VERSION_IMMUTABLE: cannot % a frozen snapshot (request_id=%, revision=%)', TG_OP, COALESCE(NEW.request_id, OLD.request_id), COALESCE(NEW.revision, OLD.revision);
                RETURN NULL;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER request_input_versions_immutability_trg BEFORE UPDATE OR DELETE ON request_input_versions FOR EACH ROW EXECUTE FUNCTION request_input_version_immutability_guard()');

        // ----------------------------------------------------------------
        // Historial de estado (append-only)
        // ----------------------------------------------------------------
        Schema::create('request_state_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 32)->default('user');
            $table->string('reason', 255)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['request_id', 'created_at']);
        });
        DB::statement('ALTER TABLE request_state_events ADD CONSTRAINT rse_request_fk FOREIGN KEY (request_id) REFERENCES planning_requests(id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE request_state_events ADD CONSTRAINT rse_actor_fk FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL');

        // ----------------------------------------------------------------
        // Pivotes de selección curricular
        // ----------------------------------------------------------------
        Schema::create('request_curricular_contents', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('curricular_content_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['request_id', 'curricular_content_id']);
            $table->index('curricular_content_id');
        });
        DB::statement('ALTER TABLE request_curricular_contents ADD CONSTRAINT rcc_request_version_fk FOREIGN KEY (request_id, curriculum_version_id) REFERENCES planning_requests(id, curriculum_version_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE request_curricular_contents ADD CONSTRAINT rcc_content_version_fk FOREIGN KEY (curricular_content_id, curriculum_version_id) REFERENCES curricular_contents(id, curriculum_version_id) ON DELETE RESTRICT');

        Schema::create('request_pdas', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('grade_id');
            $table->unsignedBigInteger('pda_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['request_id', 'pda_id']);
            $table->index('pda_id');
        });
        DB::statement('ALTER TABLE request_pdas ADD CONSTRAINT rpda_request_version_fk FOREIGN KEY (request_id, curriculum_version_id) REFERENCES planning_requests(id, curriculum_version_id) ON DELETE CASCADE');
        // Enforce PDA belongs to request's version AND grade in one composite FK.
        DB::statement('ALTER TABLE request_pdas ADD CONSTRAINT rpda_pda_version_grade_fk FOREIGN KEY (pda_id, curriculum_version_id, grade_id) REFERENCES pdas(id, curriculum_version_id, grade_id) ON DELETE RESTRICT');
        // Enforce grade_id matches request's grade.
        DB::statement('ALTER TABLE request_pdas ADD CONSTRAINT rpda_request_grade_fk FOREIGN KEY (request_id, grade_id) REFERENCES planning_requests(id, grade_id) ON DELETE CASCADE');

        Schema::create('request_articulating_axes', function (Blueprint $table) {
            $table->unsignedBigInteger('request_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('articulating_axis_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['request_id', 'articulating_axis_id']);
            $table->index('articulating_axis_id');
        });
        DB::statement('ALTER TABLE request_articulating_axes ADD CONSTRAINT rax_request_version_fk FOREIGN KEY (request_id, curriculum_version_id) REFERENCES planning_requests(id, curriculum_version_id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE request_articulating_axes ADD CONSTRAINT rax_axis_version_fk FOREIGN KEY (articulating_axis_id, curriculum_version_id) REFERENCES articulating_axes(id, curriculum_version_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        Schema::dropIfExists('request_articulating_axes');
        Schema::dropIfExists('request_pdas');
        Schema::dropIfExists('request_curricular_contents');
        Schema::dropIfExists('request_state_events');

        DB::statement('ALTER TABLE planning_requests DROP CONSTRAINT IF EXISTS planning_requests_current_version_fk');
        DB::statement('DROP TRIGGER IF EXISTS request_input_versions_immutability_trg ON request_input_versions');
        DB::statement('DROP FUNCTION IF EXISTS request_input_version_immutability_guard()');
        Schema::dropIfExists('request_input_versions');
        Schema::dropIfExists('planning_requests');
        DB::statement('DROP INDEX IF EXISTS pdas_id_version_grade_unique');
    }
};
