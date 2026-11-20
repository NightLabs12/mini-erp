<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Baris jurnal. journal_date didenormalisasi dari header agar indeks
 * (account_id, journal_date) langsung melayani General Ledger tanpa join.
 *
 * Keseimbangan dijaga dua lapis: JournalService untuk pesan yang ramah, dan
 * constraint trigger deferred PostgreSQL sebagai jaring pengaman. Draft boleh
 * tidak seimbang; trigger hanya menegakkan jurnal posted/reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->date('journal_date');
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('description', 255)->nullable();
            $table->string('partner_type', 50)->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'journal_date', 'id'], 'journal_lines_account_date_idx');
            $table->index('journal_id');
        });

        DB::statement(
            'ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amount_check '
            .'CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))'
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_assert_balanced() RETURNS trigger AS $$
            DECLARE
                target_journal bigint;
                journal_status varchar(10);
                total_debit numeric(18, 2);
                total_credit numeric(18, 2);
            BEGIN
                target_journal := COALESCE(NEW.journal_id, OLD.journal_id);

                SELECT status INTO journal_status FROM journals WHERE id = target_journal;

                IF journal_status IS NULL OR journal_status = 'draft' THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                  INTO total_debit, total_credit
                  FROM journal_lines
                 WHERE journal_id = target_journal;

                IF total_debit <> total_credit THEN
                    RAISE EXCEPTION 'JOURNAL_NOT_BALANCED: journal % debit % credit %',
                        target_journal, total_debit, total_credit
                        USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_balanced
            AFTER INSERT OR UPDATE OR DELETE ON journal_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION journal_lines_assert_balanced();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journals_assert_balanced() RETURNS trigger AS $$
            DECLARE
                total_debit numeric(18, 2);
                total_credit numeric(18, 2);
            BEGIN
                IF NEW.status = 'draft' THEN
                    RETURN NEW;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                  INTO total_debit, total_credit
                  FROM journal_lines
                 WHERE journal_id = NEW.id;

                IF total_debit <> total_credit THEN
                    RAISE EXCEPTION 'JOURNAL_NOT_BALANCED: journal % debit % credit %',
                        NEW.id, total_debit, total_credit
                        USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journals_balanced
            AFTER INSERT OR UPDATE OF status ON journals
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION journals_assert_balanced();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS journals_balanced ON journals');
        DB::unprepared('DROP FUNCTION IF EXISTS journals_assert_balanced()');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_balanced ON journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_lines_assert_balanced()');

        Schema::dropIfExists('journal_lines');
    }
};
