<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Certificate verification
    |--------------------------------------------------------------------------
    |
    | The grader's public page for a certificate number, keyed by the Grader enum value, with `{cert}` standing for
    | the number. A grader with no template shows the number without a link.
    |
    */

    'verify_urls' => [
        'psa' => 'https://www.psacard.com/cert/{cert}',
        'bgs' => null,
        'cgc' => null,
    ],

];
