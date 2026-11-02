<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInfrastructureManagement extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('v2_infra_host')) {
            Schema::create('v2_infra_host', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('machine_id')->unique();
                $table->foreign('machine_id')->references('id')->on('v2_machine')->onDelete('restrict');
                $table->string('name', 100);
                $table->boolean('enabled')->default(false);
                $table->json('features');
                $table->json('settings');
                $table->json('status')->nullable();
                $table->unsignedInteger('reported_at')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        if (!Schema::hasTable('v2_infra_task')) {
            Schema::create('v2_infra_task', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('host_id');
                $table->foreign('host_id')->references('id')->on('v2_infra_host')->onDelete('restrict');
                $table->string('action', 40);
                $table->string('state', 20)->default('queued');
                $table->text('encrypted_payload');
                $table->json('result')->nullable();
                $table->unsignedInteger('expires_at');
                $table->unsignedInteger('dispatched_at')->nullable();
                $table->unsignedInteger('completed_at')->nullable();
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->index(['host_id', 'state', 'created_at']);
            });
        }
        if (!Schema::hasTable('v2_infra_backup')) {
            Schema::create('v2_infra_backup', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('host_id');
                $table->foreign('host_id')->references('id')->on('v2_infra_host')->onDelete('restrict');
                $table->uuid('task_id')->unique();
                $table->string('kind', 20);
                $table->string('state', 20)->default('uploading');
                $table->string('filename', 191);
                $table->string('sha256', 64)->nullable();
                $table->unsignedBigInteger('bytes')->default(0);
                $table->unsignedBigInteger('expected_bytes');
                $table->text('encrypted_password');
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        if (!Schema::hasTable('v2_infra_certificate')) {
            Schema::create('v2_infra_certificate', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('host_id');
                $table->foreign('host_id')->references('id')->on('v2_infra_host')->onDelete('restrict');
                $table->unsignedBigInteger('credential_id');
                $table->foreign('credential_id')->references('id')->on('v2_cloudflare_credential')->onDelete('restrict');
                $table->string('zone_id', 32);
                $table->string('domain', 253);
                $table->string('email', 254);
                $table->boolean('enabled')->default(false);
                $table->boolean('staging')->default(true);
                $table->json('status')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
                $table->unique(['host_id', 'domain', 'staging'], 'infra_cert_host_domain');
            });
        }
        if (!Schema::hasTable('v2_infra_cluster')) {
            Schema::create('v2_infra_cluster', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 100)->unique();
                $table->unsignedBigInteger('primary_host_id');
                $table->unsignedBigInteger('standby_host_id');
                foreach (['primary_host_id', 'standby_host_id'] as $key) $table->foreign($key)->references('id')->on('v2_infra_host')->onDelete('restrict');
                $table->unsignedBigInteger('credential_id')->nullable();
                $table->foreign('credential_id')->references('id')->on('v2_cloudflare_credential')->onDelete('restrict');
                $table->boolean('enabled')->default(false);
                $table->boolean('sync_enabled')->default(false);
                $table->boolean('failover_enabled')->default(false);
                $table->boolean('split_enabled')->default(false);
                $table->json('settings');
                $table->text('encrypted_secrets')->nullable();
                $table->json('status')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        if (!Schema::hasTable('v2_infra_mesh')) {
            Schema::create('v2_infra_mesh', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 100)->unique();
                $table->unsignedBigInteger('credential_id');
                $table->foreign('credential_id')->references('id')->on('v2_cloudflare_credential')->onDelete('restrict');
                $table->boolean('enabled')->default(false);
                $table->json('settings');
                $table->json('status')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedInteger('created_at');
                $table->unsignedInteger('updated_at');
            });
        }
        if (!Schema::hasTable('v2_infra_event')) {
            Schema::create('v2_infra_event', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('cluster_id')->nullable();
                $table->unsignedBigInteger('host_id')->nullable();
                $table->string('agent_event_id', 24)->nullable();
                $table->string('kind', 40);
                $table->string('level', 10)->default('info');
                $table->text('message');
                $table->json('details')->nullable();
                $table->unsignedInteger('created_at');
                $table->index(['cluster_id', 'created_at']);
                $table->unique(['host_id', 'agent_event_id'], 'infra_agent_event_once');
            });
        }
    }

    public function down()
    {
        foreach (['v2_infra_event', 'v2_infra_mesh', 'v2_infra_cluster', 'v2_infra_certificate', 'v2_infra_backup', 'v2_infra_task', 'v2_infra_host'] as $table) Schema::dropIfExists($table);
    }
}
