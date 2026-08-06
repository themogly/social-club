<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum document upload size (kilobytes)
    |--------------------------------------------------------------------------
    |
    | The application's OWN ceiling for anything written to the private `documents`
    | disk — ID scans, medical certificates, member photos, batch lab reports,
    | purchase invoices and expense receipts, plus the applicant's optional photo.
    |
    | It exists so an oversize file is refused BY THE APPLICATION, before the upload,
    | with a message naming the limit — instead of by the web server before PHP ever
    | runs, which leaves nothing in the Laravel log and reaches the member as
    | Livewire's generic "failed to upload" (prompt 164).
    |
    | It MUST stay below the smallest server limit or it never fires. Deployment has to
    | satisfy all three, and the first is the one that was rejecting a 3.86 MB DNI photo:
    |
    |     nginx   client_max_body_size   >= 20M   (headroom above this ceiling)
    |     php     upload_max_filesize    >= 12M   (stock default is 2M — too low)
    |     php     post_max_size          >= 12M   (stock default is 8M — too low)
    |
    | 12 MB is also exactly Livewire's own default temporary-upload rule (max:12288), so
    | the app's ceiling and the transport's agree rather than leaving a band in which
    | Livewire refuses generically for a file the application would have accepted.
    |
    | Env-tunable so an operator can align it with the server in the same deploy that
    | changes the server. It is deliberately NOT a database Setting — see DECISIONS.
    |
    */

    'max_upload_kb' => env('DOCUMENTS_MAX_UPLOAD_KB', 12288),

];
