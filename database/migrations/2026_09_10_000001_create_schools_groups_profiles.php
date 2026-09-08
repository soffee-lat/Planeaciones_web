<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // -----------------------------------------------------------------
        // schools
        // -----------------------------------------------------------------
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('school_type', 32);
            $table->string('state', 128);
            $table->string('municipality', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index('owner_id');
            $table->index(['owner_id', 'name']);
            // Composite unique to enable composite FK from groups (school_id, owner_id).
            $table->unique(['id', 'owner_id'], 'schools_id_owner_unique');
        });

        DB::statement("ALTER TABLE schools ADD CONSTRAINT schools_type_check CHECK (school_type IN ('public','private'))");

        // -----------------------------------------------------------------
        // groups
        // -----------------------------------------------------------------
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('school_id');
            $table->unsignedBigInteger('curriculum_version_id');
            $table->unsignedBigInteger('grade_id');
            $table->string('name');
            $table->string('school_year', 32);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->index('owner_id');
            $table->index(['owner_id', 'school_id']);
            $table->index('archived_at');
            // Composite unique enabling future planning_requests composite FK.
            $table->unique(['id', 'owner_id'], 'groups_id_owner_unique');
        });

        // FK: owner_id → users
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_owner_fk FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE');
        // FK: curriculum_version_id → curriculum_versions
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_curriculum_version_fk FOREIGN KEY (curriculum_version_id) REFERENCES curriculum_versions(id) ON DELETE RESTRICT');
        // Composite FK: (school_id, owner_id) → schools(id, owner_id) — prevents school of another owner.
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_school_owner_fk FOREIGN KEY (school_id, owner_id) REFERENCES schools(id, owner_id) ON DELETE RESTRICT');
        // Composite FK: (grade_id, curriculum_version_id) → grades(id, curriculum_version_id) — prevents grade from another version.
        DB::statement('ALTER TABLE groups ADD CONSTRAINT groups_grade_version_fk FOREIGN KEY (grade_id, curriculum_version_id) REFERENCES grades(id, curriculum_version_id) ON DELETE RESTRICT');

        // Server-side guard: curriculum_version must be published AND be the selectable one of its curriculum.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION groups_curriculum_version_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                v_published_at timestamptz;
                v_curriculum_id bigint;
                v_selectable_id bigint;
            BEGIN
                SELECT published_at, curriculum_id INTO v_published_at, v_curriculum_id
                    FROM curriculum_versions WHERE id = NEW.curriculum_version_id FOR SHARE;
                IF v_curriculum_id IS NULL THEN
                    RAISE EXCEPTION 'GROUP_CURRICULUM_VERSION_NOT_FOUND';
                END IF;
                IF v_published_at IS NULL THEN
                    RAISE EXCEPTION 'GROUP_CURRICULUM_VERSION_NOT_PUBLISHED';
                END IF;
                SELECT selectable_version_id INTO v_selectable_id
                    FROM curricula WHERE id = v_curriculum_id FOR SHARE;
                IF v_selectable_id IS NULL OR v_selectable_id <> NEW.curriculum_version_id THEN
                    RAISE EXCEPTION 'GROUP_CURRICULUM_VERSION_NOT_SELECTABLE';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::statement('CREATE TRIGGER groups_curriculum_version_trg BEFORE INSERT OR UPDATE OF curriculum_version_id ON groups FOR EACH ROW EXECUTE FUNCTION groups_curriculum_version_guard()');

        // -----------------------------------------------------------------
        // group_profiles (1:1 with groups)
        // -----------------------------------------------------------------
        Schema::create('group_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->unique()->constrained('groups')->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);

            // preferred_format_id: integración pendiente con institutional_formats (fase posterior).
            // Se deja el campo como unsignedBigInteger nullable SIN FK hasta que exista la tabla.
            $table->unsignedBigInteger('preferred_format_id')->nullable();

            $table->unsignedInteger('student_count')->nullable();
            $table->string('general_level', 64)->nullable();
            $table->text('characteristics')->nullable();
            $table->text('difficulties')->nullable();
            $table->text('educational_needs')->nullable();
            $table->unsignedInteger('session_minutes')->nullable();
            $table->text('available_materials')->nullable();
            $table->text('teaching_preferences')->nullable();
            $table->text('preferred_activities')->nullable();
            $table->text('restrictions')->nullable();
            $table->text('management_observations')->nullable();
            $table->text('required_structure')->nullable();
            $table->text('preferred_assessment_tools')->nullable();
            $table->text('additional_notes')->nullable();
            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE group_profiles ADD CONSTRAINT group_profiles_student_count_check CHECK (student_count IS NULL OR student_count BETWEEN 1 AND 200)');
        DB::statement('ALTER TABLE group_profiles ADD CONSTRAINT group_profiles_session_minutes_check CHECK (session_minutes IS NULL OR session_minutes BETWEEN 15 AND 480)');
    }

    public function down(): void
    {
        Schema::dropIfExists('group_profiles');
        DB::statement('DROP TRIGGER IF EXISTS groups_curriculum_version_trg ON groups');
        DB::statement('DROP FUNCTION IF EXISTS groups_curriculum_version_guard()');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('schools');
    }
};
