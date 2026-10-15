<?php

namespace Another\DatabaseAssistant;

use Illuminate\Support\Facades\{DB, Gate, Crypt, Validator};
use Illuminate\Support\Str;

class DatabaseTools
{
    public function db() { return DB::connection(config('assistant.connection')); }

    public function tenant($user): string
    {
        abort_unless(isset($user->tenant_id) && (string) $user->tenant_id !== '', 403);
        return (string) $user->tenant_id;
    }

    private function resource($user, string $name, string $ability = 'assistant.read'): array
    {
        $this->tenant($user);
        $resources = config('assistant.resources', []);
        abort_unless(array_key_exists($name, $resources), 422, 'Unknown resource.');
        Gate::forUser($user)->authorize($ability, [$name]);
        return $resources[$name];
    }

    private function query($user, array $resource)
    {
        return $this->db()->table($resource['table'])->where('tenant_id', $this->tenant($user));
    }

    public function schema($user): array
    {
        $this->tenant($user);
        $result = [];
        foreach (config('assistant.resources', []) as $name => $resource) {
            if (Gate::forUser($user)->allows('assistant.read', [$name])) {
                $result[$name] = ['columns' => $resource['columns'], 'writable_fields' => array_keys($resource['rules'])];
            }
        }
        return $result;
    }

    public function read($user, array $args): array
    {
        Validator::make($args, ['resource' => 'required|string', 'id' => 'nullable|integer|min:1', 'after_id' => 'nullable|integer|min:0', 'limit' => 'required|integer|min:1|max:50', 'count_only' => 'required|boolean'])->validate();
        $resource = $this->resource($user, $args['resource']);
        $query = $this->query($user, $resource);
        if (($args['id'] ?? null) !== null) $query->where('id', $args['id']);
        if (($args['after_id'] ?? null) !== null) $query->where('id', '>', $args['after_id']);
        // Fixed SQL hint, never model-supplied SQL. MySQL caps read execution time.
        $query->selectRaw('/*+ MAX_EXECUTION_TIME(1500) */ 1 AS assistant_marker');
        $this->audit($user, 'read', $args['resource']);
        if ($args['count_only']) {
            $row = $query->selectRaw('COUNT(*) AS total')->first();
            return ['count' => (int) $row->total, 'scope' => 'current tenant and supplied ID filters'];
        }
        $rows = $query->addSelect($resource['columns'])->orderBy('id')->limit($args['limit'] + 1)->get()->map(function ($row) {
            $data = (array) $row;
            unset($data['assistant_marker']);
            foreach ($data as &$value) if (is_string($value)) $value = mb_substr($value, 0, 1000);
            return $data;
        });
        return ['rows' => $rows->take($args['limit'])->values()->all(), 'has_more' => $rows->count() > $args['limit']];
    }

    public function propose($user, array $args): array
    {
        Validator::make($args, ['resource' => 'required|string', 'operation' => 'required|in:create,update,delete', 'id' => 'nullable|integer|min:1', 'values' => 'present|array'])->validate();
        $resource = $this->resource($user, $args['resource'], 'assistant.write');
        $operation = $args['operation'];
        abort_if($operation !== 'create' && empty($args['id']), 422, 'Record ID required.');
        abort_if($operation === 'create' && ($args['id'] ?? null) !== null, 422);
        abort_if(array_diff(array_keys($args['values']), array_keys($resource['rules'])), 422, 'Field not writable.');
        if ($operation === 'delete') {
            abort_if(count($args['values']) !== 0, 422);
        } else {
            Validator::make($args['values'], $resource['rules'])->validate();
        }
        $before = $operation === 'create' ? null : $this->query($user, $resource)->where('id', $args['id'])->first();
        abort_if($operation !== 'create' && !$before, 404);
        $payload = ['resource' => $args['resource'], 'operation' => $operation, 'id' => $args['id'] ?? null,
            'values' => $args['values'], 'before_hash' => $before ? hash('sha256', json_encode($before)) : null];
        $id = (string) Str::uuid();
        $this->db()->transaction(function () use ($user, $payload, $id) {
            $this->db()->table('assistant_proposals')->insert(['id' => $id, 'user_id' => (string) $user->getAuthIdentifier(), 'tenant_id' => $this->tenant($user),
                'payload' => Crypt::encryptString(json_encode($payload)), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
            $this->audit($user, 'propose', $payload['resource'], $id);
        });
        return ['proposal_id' => $id, 'operation' => $operation, 'resource' => $args['resource'], 'record_id' => $args['id'] ?? null,
            'before' => $before ? array_intersect_key((array) $before, array_flip($resource['columns'])) : null,
            'after' => $operation === 'delete' ? null : $args['values'], 'expires_in_seconds' => 600];
    }

    public function confirm($user, string $id): array
    {
        return $this->db()->transaction(function () use ($user, $id) {
            $proposal = $this->db()->table('assistant_proposals')->where('id', $id)->where('user_id', (string) $user->getAuthIdentifier())
                ->where('tenant_id', $this->tenant($user))->lockForUpdate()->first();
            abort_unless($proposal, 404);
            $payload = json_decode(Crypt::decryptString($proposal->payload), true, 512, JSON_THROW_ON_ERROR);
            $resource = $this->resource($user, $payload['resource'], 'assistant.write');
            if ($proposal->confirmed_at) return ['status' => 'already_confirmed'];
            abort_if(now()->greaterThanOrEqualTo($proposal->expires_at), 410, 'Proposal expired.');
            abort_if(array_diff(array_keys($payload['values']), array_keys($resource['rules'])), 409);
            if ($payload['operation'] !== 'delete') Validator::make($payload['values'], $resource['rules'])->validate();
            $query = $this->query($user, $resource)->where('id', $payload['id']);
            if ($payload['operation'] !== 'create') {
                $current = $query->lockForUpdate()->first();
                abort_if(!$current || !hash_equals($payload['before_hash'], hash('sha256', json_encode($current))), 409, 'Record changed. Request a fresh preview.');
            }
            match ($payload['operation']) {
                'create' => $this->db()->table($resource['table'])->insert($payload['values'] + ['tenant_id' => $this->tenant($user)]),
                'update' => $query->update($payload['values']),
                'delete' => $query->delete(),
            };
            $this->db()->table('assistant_proposals')->where('id', $id)->update(['confirmed_at' => now()]);
            $this->audit($user, 'confirmed_'.$payload['operation'], $payload['resource'], $id);
            return ['status' => 'confirmed'];
        });
    }

    private function audit($user, string $action, string $resource, ?string $id = null): void
    {
        $this->db()->table('assistant_audits')->insert(['user_id' => (string) $user->getAuthIdentifier(), 'tenant_id' => $this->tenant($user),
            'action' => $action, 'resource' => $resource, 'proposal_id' => $id, 'created_at' => now()]);
    }
}
