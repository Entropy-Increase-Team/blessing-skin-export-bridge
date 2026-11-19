<?php

namespace SkinLoomBridge\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SkinLoomBridge\Support\TextureReader;
use Symfony\Component\HttpFoundation\Response;

use function in_array;
use function is_resource;
use function is_string;
use function strlen;

class BridgeController extends Controller
{
    private const PROTOCOL_VERSION = 1;

    private const ALLOWED_TABLES = [
        'users',
        'players',
        'textures',
        'user_closet',
        'reports',
        'notifications',
        'uuid',
        'language_lines',
        'oauth_clients',
        'invitation_codes',
        'mojang_verifications',
        'textures_description',
    ];

    private const READ_TABLES = [
        'users',
        'players',
        'textures',
        'user_closet',
        'reports',
        'notifications',
        'options',
        'uuid',
        'language_lines',
        'oauth_clients',
    ];

    private const EXTENSION_TABLES = [
        'texture-description' => 'textures_description',
        'invitation-codes' => 'invitation_codes',
        'mojang-verification' => 'mojang_verifications',
    ];

    public function manifest(): JsonResponse
    {
        $exportSensitive = (bool) option('skinloom_bridge_export_sensitive', true);

        $options = [];
        if (Schema::hasTable('options')) {
            foreach (DB::table('options')->select(['option_name', 'option_value'])->get() as $row) {
                $name = (string) $row->option_name;
                // Never re-export this plugin's internal configuration keys or revoked secrets.
                if (str_starts_with($name, 'skinloom_bridge_')) {
                    continue;
                }
                if (!$exportSensitive && $name === 'ygg_private_key') {
                    continue;
                }
                $options[$name] = (string) ($row->option_value ?? '');
            }
        }

        $counts = [
            'users' => Schema::hasTable('users') ? (int) DB::table('users')->count() : 0,
            'players' => Schema::hasTable('players') ? (int) DB::table('players')->count() : 0,
            'textures' => Schema::hasTable('textures') ? (int) DB::table('textures')->count() : 0,
            'closet' => Schema::hasTable('user_closet') ? (int) DB::table('user_closet')->count() : 0,
            'reports' => Schema::hasTable('reports') ? (int) DB::table('reports')->count() : 0,
            'notifications' => Schema::hasTable('notifications')
                ? (int) DB::table('notifications')->whereNull('read_at')->count()
                : 0,
        ];

        $missing = [];
        foreach (['user_closet', 'reports', 'notifications', 'language_lines', 'oauth_clients'] as $opt) {
            if (!Schema::hasTable($opt)) {
                $missing[] = $opt;
            }
        }

        $prefix = (string) DB::connection()->getTablePrefix();
        $otherTables = [];
        foreach ($this->listAllTables() as $fullName) {
            if (!preg_match('/^[A-Za-z0-9_$-]+$/', $fullName)) {
                continue;
            }
            if ($prefix !== '' && !str_starts_with($fullName, $prefix)) {
                continue;
            }
            $short = $prefix !== '' ? substr($fullName, strlen($prefix)) : $fullName;
            if ($short === '' || in_array($short, self::READ_TABLES, true) || !preg_match('/^[A-Za-z0-9_$-]+$/', $short)) {
                continue;
            }
            $otherTables[] = ['name' => $short, 'rows' => $this->boundedCount($short)];
        }

        $extensions = [];
        foreach (self::EXTENSION_TABLES as $plugin => $table) {
            if (Schema::hasTable($table)) {
                $rows = $this->boundedCount($table);
                if ($rows >= 0) {
                    $extensions[$plugin] = [
                        'table' => $table,
                        'rows' => $rows,
                    ];
                }
            }
        }

        $transition = [];
        $rawTransition = trim((string) env('PASSWORD_METHODS', ''));
        if ($rawTransition !== '') {
            $methods = preg_split('/,\s*/', $rawTransition) ?: [];
            $salts = explode(',', (string) config('secure.salt', env('SALT', '')));
            foreach ($methods as $idx => $name) {
                $name = trim($name);
                if ($name === '') {
                    continue;
                }
                $transition[] = [
                    'name' => $name,
                    'salt' => isset($salts[$idx]) ? trim($salts[$idx]) : '',
                ];
            }
        }

        $mailHost = trim((string) (config('mail.mailers.smtp.host') ?? config('mail.host') ?? env('MAIL_HOST', '')));
        $mail = [
            'mailer' => strtolower((string) (config('mail.default') ?? config('mail.driver') ?? env('MAIL_MAILER', env('MAIL_DRIVER', 'smtp')))),
            'host' => $mailHost,
            'port' => (string) (config('mail.mailers.smtp.port') ?? config('mail.port') ?? env('MAIL_PORT', '587')),
            'username' => (string) (config('mail.mailers.smtp.username') ?? config('mail.username') ?? env('MAIL_USERNAME', '')),
            'password' => $exportSensitive ? (string) (config('mail.mailers.smtp.password') ?? config('mail.password') ?? env('MAIL_PASSWORD', '')) : '',
            'encryption' => strtolower((string) (config('mail.mailers.smtp.encryption') ?? config('mail.encryption') ?? env('MAIL_ENCRYPTION', ''))),
            'fromAddress' => (string) (config('mail.from.address') ?? env('MAIL_FROM_ADDRESS', '')),
            'fromName' => (string) (config('mail.from.name') ?? env('MAIL_FROM_NAME', '')),
            'hostSet' => $mailHost !== '' && $mailHost !== 'smtp.mailgun.org' && $mailHost !== 'mailpit',
        ];

        $writeFrozen = (bool) option('skinloom_bridge_freeze_writes', false)
            || (function_exists('app') && method_exists(app(), 'isDownForMaintenance') && app()->isDownForMaintenance());

        return $this->json([
            'protocolVersion' => self::PROTOCOL_VERSION,
            'pluginVersion' => (string) (optional(plugin('blessing-skin-export-bridge'))->version ?? '1.0.0'),
            'capabilities' => [
                'tables.core',
                'textures.stream',
                'textures.head-ihdr',
                'utf8.substitute',
                'extensions.v1',
                'extensions.rows.v1',
                'mail.v1',
            ],
            'bsVersion' => (string) config('app.version', ''),
            'prefix' => $prefix,
            'timezone' => (string) config('app.timezone', 'Asia/Shanghai'),
            'fsDriver' => (string) config('filesystems.disks.textures.driver', 'local'),
            'writeFrozen' => $writeFrozen,
            'passwordMethod' => (string) config('secure.cipher', env('PWD_METHOD', 'BCRYPT')),
            'salt' => (string) config('secure.salt', env('SALT', '')),
            'transition' => $transition,
            'mail' => $mail,
            'uuidTable' => Schema::hasTable('uuid'),
            'missingTables' => $missing,
            'otherTables' => $otherTables,
            'extensions' => $extensions,
            'options' => $options,
            'counts' => $counts,
        ]);
    }

    public function rows(Request $request): JsonResponse
    {
        $table = (string) $request->query('table', '');
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            return $this->json([
                'error' => 'invalid_table',
                'message' => 'Unsupported table requested.',
            ], 400);
        }
        if (!Schema::hasTable($table)) {
            return $this->json([
                'table' => $table,
                'rows' => [],
                'nextCursor' => null,
                'hasMore' => false,
            ]);
        }

        $after = (string) $request->query('after', '');
        $limit = max(1, min(1000, (int) $request->query('limit', 500)));
        $exportSensitive = (bool) option('skinloom_bridge_export_sensitive', true);

        $rows = [];
        $nextCursor = null;
        $hasMore = false;

        switch ($table) {
            case 'users':
                $cols = ['uid', 'email', 'nickname', 'score', 'avatar', 'password', 'ip', 'permission', 'last_sign_at', 'register_at'];
                if (Schema::hasColumn('users', 'verified')) {
                    $cols[] = 'verified';
                }
                if (Schema::hasColumn('users', 'locale')) {
                    $cols[] = 'locale';
                }
                $q = DB::table('users')->select($cols)->orderBy('uid');
                if ($after !== '') {
                    $q->where('uid', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->uid,
                        'email' => (string) ($r->email ?? ''),
                        'nickname' => (string) ($r->nickname ?? ''),
                        'password' => (string) ($r->password ?? ''),
                        'ip' => (string) ($r->ip ?? ''),
                        'locale' => (string) ($r->locale ?? ''),
                        'score' => (int) ($r->score ?? 0),
                        'avatar' => (int) ($r->avatar ?? 0),
                        'permission' => (int) ($r->permission ?? 0),
                        'verified' => (bool) ($r->verified ?? false),
                        'lastSignAt' => $r->last_sign_at !== null ? (string) $r->last_sign_at : '',
                        'registeredAt' => $r->register_at !== null ? (string) $r->register_at : '',
                    ];
                    $nextCursor = (string) $r->uid;
                }
                break;

            case 'players':
                $q = DB::table('players')
                    ->select(['pid', 'uid', 'name', 'tid_skin', 'tid_cape', 'last_modified'])
                    ->orderBy('pid');
                if ($after !== '') {
                    $q->where('pid', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->pid,
                        'userId' => (int) ($r->uid ?? 0),
                        'name' => (string) ($r->name ?? ''),
                        'skin' => (int) ($r->tid_skin ?? 0),
                        'cape' => (int) ($r->tid_cape ?? 0),
                        'modifiedAt' => $r->last_modified !== null ? (string) $r->last_modified : '',
                    ];
                    $nextCursor = (string) $r->pid;
                }
                break;

            case 'textures':
                $q = DB::table('textures')
                    ->select(['tid', 'name', 'type', 'hash', 'size', 'uploader', 'public', 'upload_at'])
                    ->orderBy('tid');
                if ($after !== '') {
                    $q->where('tid', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->tid,
                        'name' => (string) ($r->name ?? ''),
                        'type' => (string) ($r->type ?? ''),
                        'hash' => (string) ($r->hash ?? ''),
                        'sizeKB' => (int) ($r->size ?? 0),
                        'uploader' => (int) ($r->uploader ?? 0),
                        'public' => (bool) ($r->public ?? false),
                        'uploadedAt' => $r->upload_at !== null ? (string) $r->upload_at : '',
                    ];
                    $nextCursor = (string) $r->tid;
                }
                break;

            case 'user_closet':
                $q = DB::table('user_closet')
                    ->select(['user_uid', 'texture_tid', 'item_name'])
                    ->orderBy('user_uid')
                    ->orderBy('texture_tid');
                if ($after !== '' && str_contains($after, ':')) {
                    [$lastU, $lastT] = array_map('intval', explode(':', $after, 2));
                    $q->where(function ($sub) use ($lastU, $lastT) {
                        $sub->where('user_uid', '>', $lastU)
                            ->orWhere(function ($eq) use ($lastU, $lastT) {
                                $eq->where('user_uid', '=', $lastU)->where('texture_tid', '>', $lastT);
                            });
                    });
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'userId' => (int) $r->user_uid,
                        'textureId' => (int) $r->texture_tid,
                        'name' => (string) ($r->item_name ?? ''),
                    ];
                    $nextCursor = $r->user_uid.':'.$r->texture_tid;
                }
                break;

            case 'reports':
                $q = DB::table('reports')
                    ->select(['id', 'tid', 'uploader', 'reporter', 'reason', 'status', 'report_at'])
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'textureId' => (int) ($r->tid ?? 0),
                        'uploader' => (int) ($r->uploader ?? 0),
                        'reporter' => (int) ($r->reporter ?? 0),
                        'reason' => (string) ($r->reason ?? ''),
                        'status' => (int) ($r->status ?? 0),
                        'reportedAt' => $r->report_at !== null ? (string) $r->report_at : '',
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'notifications':
                $q = DB::table('notifications')
                    ->select(['id', 'notifiable_type', 'notifiable_id', 'data', 'created_at'])
                    ->whereNull('read_at')
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'notifiableType' => (string) ($r->notifiable_type ?? ''),
                        'notifiableId' => (int) ($r->notifiable_id ?? 0),
                        'data' => (string) ($r->data ?? ''),
                        'createdAt' => $r->created_at !== null ? (string) $r->created_at : '',
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'uuid':
                $q = DB::table('uuid')->select(['id', 'name', 'uuid'])->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'name' => (string) ($r->name ?? ''),
                        'uuid' => (string) ($r->uuid ?? ''),
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'language_lines':
                $q = DB::table('language_lines')->select(['id', 'group', 'key', 'text'])->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'group' => (string) ($r->group ?? ''),
                        'key' => (string) ($r->key ?? ''),
                        'text' => (string) ($r->text ?? ''),
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'oauth_clients':
                $q = DB::table('oauth_clients')
                    ->select([
                        'id',
                        'user_id',
                        'name',
                        'secret',
                        'redirect',
                        'personal_access_client',
                        'password_client',
                        'revoked',
                        'created_at',
                    ])
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'userId' => $r->user_id !== null ? (int) $r->user_id : 0,
                        'name' => (string) ($r->name ?? ''),
                        'secret' => $exportSensitive ? (string) ($r->secret ?? '') : '',
                        'redirect' => (string) ($r->redirect ?? ''),
                        'personal' => (bool) ($r->personal_access_client ?? false),
                        'password' => (bool) ($r->password_client ?? false),
                        'revoked' => (bool) ($r->revoked ?? false),
                        'createdAt' => $r->created_at !== null ? (string) $r->created_at : '',
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'invitation_codes':
                $q = DB::table('invitation_codes')
                    ->select(['id', 'code', 'generated_at', 'used_by', 'used_at'])
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'code' => (string) ($r->code ?? ''),
                        'generatedAt' => $r->generated_at !== null ? (string) $r->generated_at : '',
                        'usedBy' => (int) ($r->used_by ?? 0),
                        'usedAt' => $r->used_at !== null ? (string) $r->used_at : '',
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'mojang_verifications':
                $q = DB::table('mojang_verifications')
                    ->select(['id', 'user_id', 'uuid', 'verified', 'created_at'])
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'userId' => (int) ($r->user_id ?? 0),
                        'uuid' => (string) ($r->uuid ?? ''),
                        'verified' => (bool) ($r->verified ?? true),
                        'createdAt' => $r->created_at !== null ? (string) $r->created_at : '',
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;

            case 'textures_description':
                $q = DB::table('textures_description')
                    ->select(['id', 'tid', 'description'])
                    ->orderBy('id');
                if ($after !== '') {
                    $q->where('id', '>', (int) $after);
                }
                $batch = $q->limit($limit + 1)->get();
                $hasMore = $batch->count() > $limit;
                foreach ($batch->take($limit) as $r) {
                    $rows[] = [
                        'id' => (int) $r->id,
                        'textureId' => (int) ($r->tid ?? 0),
                        'description' => (string) ($r->description ?? ''),
                    ];
                    $nextCursor = (string) $r->id;
                }
                break;
        }

        return $this->json([
            'table' => $table,
            'rows' => $rows,
            'nextCursor' => $hasMore ? $nextCursor : null,
            'hasMore' => $hasMore,
        ]);
    }

    public function texture(Request $request, string $hash): Response
    {
        if (!TextureReader::isValidHash($hash)) {
            return $this->json(['error' => 'invalid_hash'], 400);
        }

        $headers = [
            'Content-Type' => 'application/octet-stream',
        ];

        if ($request->isMethod('HEAD')) {
            $meta = TextureReader::inspect($hash);
            if (!$meta['exists']) {
                return $this->json(['error' => 'not_found'], 404);
            }
            if ($meta['size'] !== null) {
                $headers['Content-Length'] = (string) $meta['size'];
            }
            if ($meta['sha256'] !== null) {
                $headers['X-Texture-Sha256'] = $meta['sha256'];
            }
            if ($meta['width'] !== null && $meta['height'] !== null) {
                $headers['X-Texture-Width'] = (string) $meta['width'];
                $headers['X-Texture-Height'] = (string) $meta['height'];
            }

            return response('', 200, $headers);
        }

        $stream = TextureReader::openStream($hash);
        if (!is_resource($stream)) {
            return $this->json(['error' => 'not_found'], 404);
        }

        try {
            $content = stream_get_contents($stream, 67108864);
        } finally {
            @fclose($stream);
        }
        if (!is_string($content)) {
            return $this->json(['error' => 'read_failed'], 500);
        }

        $headers['Content-Length'] = (string) strlen($content);
        $headers['X-Texture-Sha256'] = hash('sha256', $content);
        $dims = TextureReader::parsePngDimensions(substr($content, 0, 32));
        if ($dims !== null) {
            $headers['X-Texture-Width'] = (string) $dims[0];
            $headers['X-Texture-Height'] = (string) $dims[1];
        }

        return response($content, 200, $headers);
    }

    private function boundedCount(string $table, int $cap = 100001): int
    {
        try {
            $sub = DB::table($table)->selectRaw('1')->limit($cap);

            return (int) DB::query()->fromSub($sub, 't')->count();
        } catch (\Throwable $e) {
            return -1;
        }
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @return array<int, string>
     */
    private function listAllTables(): array
    {
        $driver = DB::connection()->getDriverName();
        $out = [];
        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $rows = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'");
            } elseif ($driver === 'pgsql') {
                $rows = DB::select("SELECT table_name AS name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'");
            } else {
                $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
            }
            foreach ($rows as $r) {
                if (isset($r->name)) {
                    $out[] = (string) $r->name;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        sort($out);

        return $out;
    }
}
