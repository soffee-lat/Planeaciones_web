<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('curricula', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('country_code', 8)->nullable();
            $table->string('educational_level')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('selectable_version_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('curriculum_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_id')->constrained('curricula')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('label');
            $table->string('source_reference')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checksum', 128)->nullable();
            $table->timestampsTz();
            $table->unique(['curriculum_id', 'number']);
            $table->unique(['id', 'curriculum_id']);
        });

        // Circular FK: curricula.selectable_version_id -> curriculum_versions.id
        DB::statement('ALTER TABLE curricula ADD CONSTRAINT curricula_selectable_version_fk FOREIGN KEY (selectable_version_id) REFERENCES curriculum_versions(id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX curricula_selectable_version_idx ON curricula (selectable_version_id)');

        Schema::create('educational_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->unique(['id', 'curriculum_version_id']);
        });

        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->unsignedBigInteger('educational_phase_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('ordinal');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->unique(['id', 'curriculum_version_id']);
        });
        DB::statement('ALTER TABLE grades ADD CONSTRAINT grades_phase_composite_fk FOREIGN KEY (educational_phase_id, curriculum_version_id) REFERENCES educational_phases(id, curriculum_version_id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX grades_phase_idx ON grades (educational_phase_id)');

        Schema::create('formative_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->unique(['id', 'curriculum_version_id']);
        });

        Schema::create('curricular_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->unsignedBigInteger('educational_phase_id');
            $table->unsignedBigInteger('formative_field_id');
            $table->string('code');
            $table->string('title');
            $table->text('full_text');
            $table->string('source_locator')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->unique(['id', 'curriculum_version_id']);
            $table->index(['curriculum_version_id', 'educational_phase_id', 'formative_field_id'], 'contents_version_phase_field_idx');
        });
        DB::statement('ALTER TABLE curricular_contents ADD CONSTRAINT contents_phase_composite_fk FOREIGN KEY (educational_phase_id, curriculum_version_id) REFERENCES educational_phases(id, curriculum_version_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE curricular_contents ADD CONSTRAINT contents_field_composite_fk FOREIGN KEY (formative_field_id, curriculum_version_id) REFERENCES formative_fields(id, curriculum_version_id) ON DELETE RESTRICT');

        Schema::create('pdas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->unsignedBigInteger('curricular_content_id');
            $table->unsignedBigInteger('grade_id');
            $table->string('code');
            $table->text('full_text');
            $table->string('source_locator')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->index(['curriculum_version_id', 'grade_id', 'curricular_content_id'], 'pdas_version_grade_content_idx');
        });
        DB::statement('ALTER TABLE pdas ADD CONSTRAINT pdas_content_composite_fk FOREIGN KEY (curricular_content_id, curriculum_version_id) REFERENCES curricular_contents(id, curriculum_version_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE pdas ADD CONSTRAINT pdas_grade_composite_fk FOREIGN KEY (grade_id, curriculum_version_id) REFERENCES grades(id, curriculum_version_id) ON DELETE RESTRICT');

        Schema::create('articulating_axes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curriculum_version_id')->constrained('curriculum_versions')->restrictOnDelete();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['curriculum_version_id', 'code']);
            $table->unique(['id', 'curriculum_version_id']);
        });

        // ------------------------------------------------------------------
        // Immutability triggers
        // ------------------------------------------------------------------

        // Guard for child element tables: any I/U/D against an element that
        // belongs to a published version is rejected. Used on 6 child tables.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION curriculum_element_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_version_id bigint;
                v_published_at timestamptz;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    v_version_id := OLD.curriculum_version_id;
                ELSE
                    v_version_id := NEW.curriculum_version_id;
                    IF TG_OP = 'UPDATE' AND NEW.curriculum_version_id <> OLD.curriculum_version_id THEN
                        RAISE EXCEPTION 'CURRICULUM_VERSION_LOCKED: cannot move element between versions';
                    END IF;
                END IF;
                SELECT published_at INTO v_published_at FROM curriculum_versions WHERE id = v_version_id FOR SHARE;
                IF v_published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'CURRICULUM_VERSION_PUBLISHED: cannot % element on published version %', TG_OP, v_version_id;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        foreach ([
            'educational_phases',
            'grades',
            'formative_fields',
            'curricular_contents',
            'pdas',
            'articulating_axes',
        ] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutability_trg BEFORE INSERT OR UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION curriculum_element_immutability_guard()");
        }

        // Guard for curriculum_versions itself. A published version can never
        // be modified or deleted; publishing (setting published_at from NULL
        // to NOT NULL) is the only allowed transition and requires published_by.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION curriculum_version_immutability_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.published_at IS NOT NULL THEN
                        RAISE EXCEPTION 'CURRICULUM_VERSION_PUBLISHED: cannot delete published version %', OLD.id;
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.published_at IS NOT NULL THEN
                    RAISE EXCEPTION 'CURRICULUM_VERSION_PUBLISHED: cannot update published version %', OLD.id;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.published_at IS NULL AND NEW.published_at IS NOT NULL THEN
                    IF NEW.published_by IS NULL THEN
                        RAISE EXCEPTION 'CURRICULUM_VERSION_INVALID_PUBLISH: published_by is required when publishing';
                    END IF;
                    IF NEW.checksum IS NULL OR length(NEW.checksum) = 0 THEN
                        RAISE EXCEPTION 'CURRICULUM_VERSION_INVALID_PUBLISH: checksum is required when publishing';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER curriculum_versions_immutability_trg BEFORE UPDATE OR DELETE ON curriculum_versions FOR EACH ROW EXECUTE FUNCTION curriculum_version_immutability_guard()');

        // selectable_version_id must reference a PUBLISHED version of the same curriculum.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION curriculum_selectable_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_curriculum_id bigint;
                v_published_at timestamptz;
            BEGIN
                IF NEW.selectable_version_id IS NULL THEN RETURN NEW; END IF;
                SELECT curriculum_id, published_at INTO v_curriculum_id, v_published_at
                    FROM curriculum_versions WHERE id = NEW.selectable_version_id FOR SHARE;
                IF v_curriculum_id IS NULL THEN
                    RAISE EXCEPTION 'SELECTABLE_VERSION_NOT_FOUND';
                END IF;
                IF v_curriculum_id <> NEW.id THEN
                    RAISE EXCEPTION 'SELECTABLE_VERSION_MISMATCHED_CURRICULUM';
                END IF;
                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'SELECTABLE_VERSION_NOT_PUBLISHED';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER curricula_selectable_version_trg BEFORE INSERT OR UPDATE OF selectable_version_id ON curricula FOR EACH ROW EXECUTE FUNCTION curriculum_selectable_version_guard()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS curricula_selectable_version_trg ON curricula');
        DB::statement('DROP TRIGGER IF EXISTS curriculum_versions_immutability_trg ON curriculum_versions');
        foreach ([
            'articulating_axes',
            'pdas',
            'curricular_contents',
            'formative_fields',
            'grades',
            'educational_phases',
        ] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutability_trg ON {$table}");
        }
        DB::statement('DROP FUNCTION IF EXISTS curriculum_selectable_version_guard()');
        DB::statement('DROP FUNCTION IF EXISTS curriculum_version_immutability_guard()');
        DB::statement('DROP FUNCTION IF EXISTS curriculum_element_immutability_guard()');

        Schema::dropIfExists('articulating_axes');
        Schema::dropIfExists('pdas');
        Schema::dropIfExists('curricular_contents');
        Schema::dropIfExists('formative_fields');
        Schema::dropIfExists('grades');
        Schema::dropIfExists('educational_phases');
        DB::statement('ALTER TABLE curricula DROP CONSTRAINT IF EXISTS curricula_selectable_version_fk');
        Schema::dropIfExists('curriculum_versions');
        Schema::dropIfExists('curricula');
    }
};
