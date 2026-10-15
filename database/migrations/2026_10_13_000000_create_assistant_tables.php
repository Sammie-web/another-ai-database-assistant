<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $schema = Schema::connection(config('assistant.connection'));
        $schema->create('assistant_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('user_id');
            $t->string('tenant_id');
            $t->text('payload');
            $t->timestamp('expires_at');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['user_id', 'tenant_id']);
        });
        $schema->create('assistant_audits', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('user_id');
            $t->string('tenant_id');
            $t->string('action');
            $t->string('resource');
            $t->uuid('proposal_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('assistant.connection'))->dropIfExists('assistant_audits');
        Schema::connection(config('assistant.connection'))->dropIfExists('assistant_proposals');
    }
};
