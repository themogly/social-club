<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        // Sensitive member documents (Article 9). Separate from general uploads:
        // private, never publicly served, accessed only via short-lived, user-bound,
        // access-logged signed URLs. The ID scans / medical certs / generated PDFs are
        // ENCRYPTED at rest via App\Support\DocumentVault (Crypt, AES-256) before write
        // and decrypted only at the streaming endpoint (prompt 32). The member photo and
        // POS signature are now on the SAME footing (prompt 113): encrypted at rest and
        // served only through the authorised, access-logged endpoint. Non-member business
        // uploads (purchase invoices, expense/batch/article docs) are private but NOT
        // Article-9 and stay unencrypted here by decision (see DECISIONS.md, prompt 113).
        // Local dev uses the local driver; production sets DOCUMENTS_DRIVER=s3
        // with a dedicated private bucket (AWS_DOCUMENTS_BUCKET). `throw => true`
        // so a lost/failed ID-scan write fails loud, never silently.
        'documents' => [
            'driver' => env('DOCUMENTS_DRIVER', 'local'),
            // `root` is a LOCAL-driver concept — a real filesystem path. Laravel's createS3Driver() passes the
            // SAME `root` to the S3 adapter as the object-KEY PREFIX (prompt 162), so a storage_path() there
            // prefixes every ID scan / photo with the server's absolute path (e.g. /home/…/storage/app/private/
            // documents/…): it leaks the server layout into the bucket and — fatally for a disk with no second
            // copy — makes every existing object unreachable after any move, rename, redeploy or restore, because
            // storage_path() resolves from wherever the app now lives. So: LOCAL keeps its path; S3 gets an
            // EXPLICIT, configurable prefix that DEFAULTS TO EMPTY (a flat bucket root — the app already
            // namespaces every write by directory: member-id-scans/…, member-photos/…, org-logos/…). Never a
            // derived path. Set DOCUMENTS_S3_PREFIX only for a deliberate shared-bucket / per-club layout.
            'root' => env('DOCUMENTS_DRIVER', 'local') === 's3'
                ? env('DOCUMENTS_S3_PREFIX', '')
                : storage_path('app/private/documents'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_DOCUMENTS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // S3 server-side encryption (SSE-KMS) — defence in depth ON TOP OF the app-layer
            // DocumentVault encryption; both matter (prompt 32). Set AWS_DOCUMENTS_SSE=aws:kms
            // + a key id in production. No-op for the local driver / when unset.
            'options' => array_filter([
                'ServerSideEncryption' => env('AWS_DOCUMENTS_SSE'),
                'SSEKMSKeyId' => env('AWS_DOCUMENTS_SSE_KMS_KEY_ID'),
            ]),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
