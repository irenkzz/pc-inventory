<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classification_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('rule_type', 80);
            $table->string('match_value');
            $table->string('output_value');
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['rule_type', 'match_value']);
            $table->index(['rule_type', 'is_active', 'priority']);
        });

        $now = now();
        foreach ($this->defaultRules() as $rule) {
            DB::table('classification_rules')->insert([
                ...$rule,
                'is_active' => true,
                'priority' => 100,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_rules');
    }

    private function defaultRules(): array
    {
        return [
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'BRT', 'output_value' => 'Pemberitaan', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'EDT', 'output_value' => 'Teknik', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'MCR', 'output_value' => 'Teknik', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'PGM', 'output_value' => 'Program', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'IT', 'output_value' => 'IT', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'asset_prefix_department', 'match_value' => 'SRV', 'output_value' => 'IT', 'notes' => 'Asset prefix department mapping.'],
            ['rule_type' => 'site_alias', 'match_value' => 'SITE-HQ', 'output_value' => 'Bhayangkara', 'notes' => 'Scanner/site ID display mapping.'],
            ['rule_type' => 'site_alias', 'match_value' => 'HEAD OFFICE', 'output_value' => 'Bhayangkara', 'notes' => 'Legacy scanner site display mapping.'],
        ];
    }
};
