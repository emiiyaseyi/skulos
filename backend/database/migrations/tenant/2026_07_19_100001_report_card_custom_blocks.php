<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom text blocks for the report card (user request 2026-07-19: "add
 * option to add text, charts etc to the report card and which can allow
 * me allow something like address etc"). A school writes its own free
 * text (an address line, a disclaimer, a scholarship note, anything the
 * fixed field catalog does not cover), gives it a short label, and it
 * then behaves exactly like any other body section in the Layout
 * Designer: draggable, reorderable, alignable, removable. Not tied to
 * any one report card, a school-wide library it can reuse across every
 * template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_custom_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_custom_blocks');
    }
};
